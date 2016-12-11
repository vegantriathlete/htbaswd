<?php

/**
 * @file Process MailChimp web hooks
 *
 * This is the program that is called by MailChimp when changes are made
 * to the How to Be a Successful Web Developer email list.
 *
 * This program assumes that there is an ini_files_info.ini file located in the
 * same directory where this program is located.
 *
 * Expected contents of ini_files_info.ini:
 * path = "/path/to/your/ini/files"
 * mysql = "nameofyourmysqlconfigfile"
 * webhook = "nameofyourwebhookconfigfile"
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
 * This program expects that there will be a webhook config file with the name
 * and location as specified in the ini_files_info.ini file.
 *
 * Expected contents of webhook config file:
 * key = "keypassedasqueryargument"
 *
 * Finally, this program assumes that there is an error-reporting.sh executable
 * file located in the same directory where this program is located that takes
 * care of reporting errors.
 */

set_exception_handler('handleException');

// Retrieve file data
if (($ini_files_data = @parse_ini_file(dirname(__FILE__) . "/ini_files_info.ini")) == FALSE) {
  throw new Exception("Could not parse ini file: ini_files_info.ini");
}
$ini_file_base_path = $ini_files_data['path'];
$mysqlCredentialsFile = $ini_files_data['mysql'];
$webhookCredentialsFile = $ini_files_data['webhook'];

// Define file locations and database connection information
define("INIFILEBASEPATH", $ini_file_base_path);
define("MYSQLCREDENTIALS", $ini_file_base_path . $mysqlCredentialsFile);
define("WEBHOOKCREDENTIALS", $ini_file_base_path . $webhookCredentialsFile);

// Parse the credentials files
if (($mysql_credentials = @parse_ini_file(MYSQLCREDENTIALS)) == FALSE) {
  $error = 'Unable to parse database credentials file!';
  wh_log($error);
  throw new Exception($error);
}
if (($webhook_credentials = @parse_ini_file(WEBHOOKCREDENTIALS)) == FALSE) {
  $error = 'Unable to parse web hook credentials file!';
  wh_log($error);
  throw new Exception($error);
}

// Receive the request
wh_log('==================[ Incoming Request ]==================');
wh_log("Full _REQUEST dump:\n".print_r($_REQUEST,true)); 

if ( !isset($_GET['key']) ){
  wh_log('No security key specified, ignoring request'); 
} elseif ($_GET['key'] != $webhook_credentials['key']) {
  wh_log('Security key specified, but not correct:');
  wh_log("\t".'Wanted: "' . $webhook_credentials['key'] . '", but received "'.$_GET['key'].'"');
} else {
  define("DSN", "mysql:host=" . $mysql_credentials['host'] . ";dbname=" . $mysql_credentials['database']);
  // Process the request
  wh_log('Processing a "'.$_POST['type'].'" request...');
  switch($_POST['type']){
    case 'subscribe'  : subscribe($_POST['data'], $mysql_credentials);
      break;
    case 'unsubscribe': unsubscribe($_POST['data'], $mysql_credentials);
      break;
    case 'cleaned'    : cleaned($_POST['data'], $mysql_credentials);
      break;
    case 'upemail'    : upemail($_POST['data'], $mysql_credentials);
      break;
    case 'profile'    : profile($_POST['data'], $mysql_credentials);
      break;
    default:
      wh_log('Request type "'.$_POST['type'].'" unknown, ignoring.');
  }
}

// Complete processing
wh_log('Finished processing request.');
exit(0);

/**
 * Write a log entry to the webhook log file
 *
 * @param string $msg
 *   The message to be written to the log file
 */
function wh_log($msg){
    $logfile = INIFILEBASEPATH . 'webhook_log';
    file_put_contents($logfile,date("Y-m-d H:i:s")." | ".$msg."\n",FILE_APPEND);
}

/**
 * Process a new subscriber to the email list
 *
 * @param array $data
 *   The $_POST data that MailChimp sent
 * @param array $mysql_credentials
 *   The credentials to sign in to the database
 */
function subscribe($data, $mysql_credentials) {
  wh_log($data['email'] . ' just subscribed!');
  $dsn = DSN;
  $username = $mysql_credentials['user'];
  $password = $mysql_credentials['password'];
  $db_insert_h = new PDO($dsn, $username, $password);
  $nextrun = time();
  $sql = 'INSERT INTO subscribers (email, nextrun, nextfile, uid) VALUES (:email, :nextrun, :nextfile, :uid);';
  $vars = array('nextrun' => $nextrun, 'nextfile' => 'email_01.ini', 'email' => $data['email'], 'uid' => $data['id']);
  $insert = $db_insert_h->prepare($sql);
  $insert->execute($vars);
}

/**
 * Process a request to unsubscribe from the email list
 *
 * @param array $data
 *   The $_POST data that MailChimp sent
 * @param array $mysql_credentials
 *   The credentials to sign in to the database
 */
function unsubscribe($data, $mysql_credentials) {
  wh_log($data['email'] . ' just unsubscribed!');
  $dsn = DSN;
  $username = $mysql_credentials['user'];
  $password = $mysql_credentials['password'];
  $db_delete_h = new PDO($dsn, $username, $password);
  $sql = 'DELETE FROM subscribers WHERE email = :email';
  $vars = array('email' => $data['email']);
  $delete = $db_delete_h->prepare($sql);
  $delete->execute($vars);
}

/**
 * Clean an email address from the email list
 *
 * @param array $data
 *   The $_POST data that MailChimp sent
 * @param array $mysql_credentials
 *   The credentials to sign in to the database
 */
function cleaned($data, $mysql_credentials) {
  wh_log($data['email'] . ' was cleaned from your list!');
  $dsn = DSN;
  $username = $mysql_credentials['user'];
  $password = $mysql_credentials['password'];
  $db_delete_h = new PDO($dsn, $username, $password);
  $sql = 'DELETE FROM subscribers WHERE email = :email';
  $vars = array('email' => $data['email']);
  $delete = $db_delete_h->prepare($sql);
  $delete->execute($vars);
}

/**
 * Update an email address on the email list
 *
 * @param array $data
 *   The $_POST data that MailChimp sent
 * @param array $mysql_credentials
 *   The credentials to sign in to the database
 */
function upemail($data, $mysql_credentials) {
  wh_log($data['old_email'] . ' changed their email address to '. $data['new_email']. '!');
  $dsn = DSN;
  $username = $mysql_credentials['user'];
  $password = $mysql_credentials['password'];
  $db_update_h = new PDO($dsn, $username, $password);
  $sql = 'UPDATE subscribers SET email = :newemail, uid = :newid WHERE email = :email';
  $vars = array('newemail' => $data['new_email'], 'email' => $data['old_email'], 'newid' => $data['new_id']);
  $update = $db_update_h->prepare($sql);
  $update->execute($vars);
}

/**
 * Process a profile change on the email list
 *
 * @param array $data
 *   The $_POST data that MailChimp sent
 * @param array $mysql_credentials
 *   The credentials to sign in to the database
 */
function profile($data, $mysql_credentials) {
    wh_log($data['email'] . ' updated their profile!');
}

/**
 * Handle any exceptions that were thrown during execution
 */
function handleException($e) {
  shell_exec(dirname(__FILE__) . '/error-reporting.sh ' . __FILE__ . '": ' . $e->getMessage() . '"');
  exit(1);
}
