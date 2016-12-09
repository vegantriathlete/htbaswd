<?php

/**
 * @file Process MailChimp web hooks
 */

// Retrieve file data
$ini_files_data = parse_ini_file(dirname(__FILE__) . "/ini_files_info.ini");
if (!is_array($ini_files_data)) {
  // @todo: Write an error message to syslog or send email
  //        Maybe write a shell script that gets called?
  exit(1);
}
$ini_file_base_path = $ini_files_data['path'];
$mysqlCredentialsFile = $ini_files_data['mysql'];
$webhookCredentialsFile = $ini_files_data['webhook'];

// Define file locations and database connection information
define("INIFILEBASEPATH", $ini_file_base_path);
define("MYSQLCREDENTIALS", $ini_file_base_path . $mysqlCredentialsFile);
define("WEBHOOKCREDENTIALS", $ini_file_base_path . $webhookCredentialsFile);

// Parse the credentials files
$mysql_credentials = parse_ini_file(MYSQLCREDENTIALS);
$webhook_credentials = parse_ini_file(WEBHOOKCREDENTIALS);
if (is_array($mysql_credentials) && is_array($webhook_credentials)) {
  define("DSN", "mysql:host=" . $mysql_credentials['host'] . ";dbname=" . $mysql_credentials['database']);

  wh_log('==================[ Incoming Request ]==================');

  wh_log("Full _REQUEST dump:\n".print_r($_REQUEST,true)); 

  if ( !isset($_GET['key']) ){
    wh_log('No security key specified, ignoring request'); 
  } elseif ($_GET['key'] != $webhook_credentials['key']) {
    wh_log('Security key specified, but not correct:');
    wh_log("\t".'Wanted: "' . $webhook_credentials['key'] . '", but received "'.$_GET['key'].'"');
  } else {
    //process the request
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
  wh_log('Finished processing request.');
} else {
  if (!is_array($mysql_credentials)) {
    wh_log('Unable to process database credentials!');
  }
  if (!is_array($webhook_credentials)) {
    wh_log('Unable to process web hook credentials!');
  }
}

/***********************************************
    Helper Functions
***********************************************/
function wh_log($msg){
    $logfile = INIFILEBASEPATH . 'webhook_log';
    file_put_contents($logfile,date("Y-m-d H:i:s")." | ".$msg."\n",FILE_APPEND);
}

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
function profile($data, $mysql_credentials) {
    wh_log($data['email'] . ' updated their profile!');
}
