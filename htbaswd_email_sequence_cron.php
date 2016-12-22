<?php

/**
 * @file htbaswd_email_sequence_cron.php - Process email subscriptions
 *
 * This is the program that is called on cron runs to read through the
 * subscribers table in the mc_webhook database in order to handle
 * sending the next email in the email sequence.
 * It reads all the rows in the table and compares the next run time to the
 * current time. If the next run time is less than the current time,
 * the next email in the sequence is sent to the subscriber.
 *   The email subject is the value for the "subject" key.
 *   The email body is the value for the "body" key.
 *   The email is sent to the "email" value for the current database row.
 *
 * When processing each subscriber, check for the next email in the sequence.
 * The next email is named in the .ini file with the next_file key.
 * If there is a next_file, then update the subscriber table with a nextrun
 * timestamp that is 24 hours from now and with the value of next_file.
 * If there is not a next_file, then delete the row from the table for this
 * subscriber.
 *
 * This program assumes that there is an ini_files_info.ini file located in the
 * same directory where this program is located.
 *
 * Expected contents of ini_files_info.ini:
 * path = "/path/to/your/ini/files/"
 * mysql = "nameofyourmysqlconfigfile"
 * canspam = "nameofyourcanspamconfigfile"
 *
 * This program expects that there will be a mysql config file with the name
 * and location as specified in the ini_files_info.ini file.
 *
 * Expected contents of mysql config file:
 * user = "yourusername"
 * password = "yourpassword"
 * host = "yourdatabasehost"
 * database = "yourdesireddatabase"
 *
 * This program expects that there will be a canspam config file with the name
 * and location as specified in the ini_files_info.ini file.
 *
 * Expected contents of canspam config file:
 * signup_url = "https://www.yourwebsite.com"
 * email_list = "https://youremaillist.us14.list-manage.com/profile/?u=somestringofcharacters&id=morechars"
 * addressee = "Your name or company name"
 * street = "123 Main Street"
 * city = "Awesometown"
 * state = "CO"
 * zip = "12345"
 * reply_to = "info@yourwebsite.com"
 *
 * Finally, this program assumes that there is an error-reporting.sh executable
 * file located in the same directory where this program is located that takes
 * care of reporting errors.
 */

set_exception_handler('handleException');

// Retrieve file data
if (($ini_files_data = @parse_ini_file(dirname(__FILE__) . "/ini_files_info.ini")) == FALSE) {
  throw new Exception("Could not access ini_files_info.ini");
}
$ini_file_base_path = $ini_files_data['path'];
$mysqlCredentialsFile = $ini_files_data['mysql'];
$canspamCredentialsFile = $ini_files_data['canspam'];

// Define file locations and database connection information
define("INIFILEBASEPATH", $ini_file_base_path);
define("MYSQLCREDENTIALS", $ini_file_base_path . $mysqlCredentialsFile);
define("CANSPAMCREDENTIALS", $ini_file_base_path . $canspamCredentialsFile);

// Capture current time for comparison to next run time
$current_time = time();

// Parse the mysql credentials
if (($mysql_credentials = @parse_ini_file(MYSQLCREDENTIALS)) == FALSE) {
  $error = 'Unable to parse database credentials!';
  cj_log($error);
  throw new Exception($error);
}

// Parse the canspam credentials
if (($canspam_credentials = @parse_ini_file(CANSPAMCREDENTIALS)) == FALSE) {
  $error = 'Unable to parse canspam credentials!';
  cj_log($error);
  throw new Exception($error);
}

// Process the subscribers
define("DSN", "mysql:host=" . $mysql_credentials['host'] . ";dbname=" . $mysql_credentials['database']);
$dsn = DSN;
$username = $mysql_credentials['user'];
$password = $mysql_credentials['password'];
$dbh = new PDO($dsn, $username, $password);
$sql = 'SELECT email, nextrun, nextfile, uid FROM subscribers;';
foreach ($dbh->query($sql) as $row) {
  if ($row['nextrun'] < $current_time) {
    $next_email_file = INIFILEBASEPATH . $row['nextfile'];
    if ($file_array = @parse_ini_file($next_email_file)) {
      format_next_email($file_array, $row['email'], $row['uid'], $canspam_credentials);
      log_email_sequence($row['email'], $row['nextfile']);
      update_subscriber_table($file_array, $row['email'], $row['nextrun'], $mysql_credentials);
    } else {
      $error = 'Unable to process email .ini file: ' . $next_email_file;
      cj_log($error);
      throw new Exception($error);
    }
  }
}

// Finish processing
exit(0);

/**
 * Create and send the next email in the sequence
 *
 * @param array $file_array
 *   A keyed array with the subject and body of the email
 * @param string $subscriber
 *   The email address to which to send the email
 * @param string $uid
 *   The UUID MailChimp assigned to the subscriber
 * @param array $canspam_credentials
 *   The information to include to comply with the CANSPAM act
 */
function format_next_email($file_array, $subscriber, $uid, $canspam_credentials) {
  $to = $subscriber;
  $subject = $file_array['subject'];
  $body = $file_array['body'];
  $next_file = $file_array['next_file'];
  append_canspam_information($body, $uid, $next_file, $canspam_credentials);
  mail($to, $subject, $body);
}

/**
 * Append the CANSPAM information to the email body
 *
 * @param string $body
 *   The email body
 * @param string $uid
 *   The UUID MailChimp assigned to the subscriber
 * @param string $next_file
 *   The name of the next file in the sequence or NULL
 * @param array $canspam_credentials
 *   The information to include to comply with the CANSPAM act
 */
function append_canspam_information(&$body, $uid, $next_file, $canspam_credentials) {
  if (!$next_file) {
    $canspam = "\r\n\r\nI hope you have enjoyed this free course. If you'd like to get more support being a successful web developer, I'd love for you to join me at https://forums.successfulwebdeveloper.com. I hope to \"see\" you there!";
  } else {
    $canspam = "\r\n\r\n\r\n\r\n\r\nWant more? Check out https://forums.successfulwebdeveloper.com\r\n";
  }
  $canspam .= "\r\n\r\nYou are receiving this email because you subscribed at " . $canspam_credentials['signup_url'] . "\r\n";
  $canspam .= "If you wish to maintain your email preferences you may visit " . $canspam_credentials['email_list'] . "&e=$uid\r\n";
  $canspam .= "\r\n\r\n" . $canspam_credentials['addressee'] . "\r\n";
  $canspam .= $canspam_credentials['street'] . "\r\n";
  $canspam .= $canspam_credentials['city'] . ", ";
  $canspam .= $canspam_credentials['state'] . " ";
  $canspam .= $canspam_credentials['zip'] . "\r\n";
  $canspam .= $canspam_credentials['reply_to'];
  $body .= $canspam;
}

/**
 * Create a log entry for sending an email
 *
 * @param string $subscriber
 *   The email address to which to send the email
 * @param string $email
 *   The name of the email file being sent
 */
function log_email_sequence($subscriber, $email) {
  $message = "Sent $email to $subscriber";
  cj_log($message);
}

/**
 * Update the subscriber table
 *
 * @param array $file_array
 *   A keyed array with the next file name
 * @param string $subscriber
 *   The email address to which to send the email
 * @param INT $nextrun
 *   A UNIX timestamp of when the next run for the subscriber was
 * @param array $mysql_credentials
 *   The credentials for the database connection
 */
function update_subscriber_table($file_array, $subscriber, $nextrun, $mysql_credentials) {
  $dsn = DSN;
  $username = $mysql_credentials['user'];
  $password = $mysql_credentials['password'];
  $db_update_h = new PDO($dsn, $username, $password);
  if ($file_array['next_file'] != NULL) {
    $nextrun += 24*60*60;
    $sql = 'UPDATE subscribers SET nextrun=:nextrun, nextfile=:nextfile WHERE email=:email;';
    $vars = array('nextrun' => $nextrun, 'nextfile' => $file_array['next_file'], 'email' => $subscriber);
  } else {
    cj_log($subscriber . ' removed from subscribers.');
    $sql = 'DELETE FROM subscribers WHERE email=:email;';
    $vars = array('email' => $subscriber);
    send_completion_notification($subscriber);
  }
  $update = $db_update_h->prepare($sql);
  $update->execute($vars);
}

/**
 * Log activity to the cron log file
 *
 * @param string $message
 *   The message to write to the log file
 */
function cj_log($message) {
  $filename = INIFILEBASEPATH . 'cj_log';
  $data = 'Log Entry - ' . date('r') . "\n" . $message . "\n";
  file_put_contents($filename, $data, FILE_APPEND);
}

/**
 * Send a notification that a subscriber has completed the course
 *
 * @param string $subscriber
 *   The email of the subscriber who has completed the course
 */
function send_completion_notification($subscriber) {
  $to = 'info@howtobeasuccessfulwebdeveloper.com';
  $subject = 'A student has completed the course!';
  $body = $subscriber . ' has made it through all eight lessons.';
  $body .= ' Check whether this person has already subscribed to ';
  $body .= 'https://forums.successfulwebdeveloper.com.';
  $body .= ' If s/he has subscribed, then remove the email address';
  $body .= ' from the info@howtobeasuccessfulwebdeveloper.com email list.';
  mail($to, $subject, $body);
}

/**
 * Handle any exceptions that are thrown during execution
 */
function handleException($e) {
  shell_exec(dirname(__FILE__) . '/error-reporting.sh ' . __FILE__ . '": ' . $e->getMessage() . '"');
  exit(1);
}
