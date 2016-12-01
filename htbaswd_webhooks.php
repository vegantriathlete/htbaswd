<?php

/**
 * @file Process MailChimp web hooks
 */

// Note: This program should have permissions set via
//       chmod u+s so that Apache can access the necessary files
//       ls -l should show rwsr-x---

// Define file locations and database connection information
define("INIFILEBASEPATH", "/private/htbaswd/email_sequence/");
define("MYSQLCREDENTIALS", "/private/htbaswd/email_sequence/.my.cnf");
define("DSN", "mysql:host=db02.isaacsonwebdevelopment.com;dbname=mc_webhook");
define("WEBHOOKKEY", "29M;;{FTpRQ4Kwqq");

// Parse the mysql credentials
$mysql_credentials = parse_ini_file(MYSQLCREDENTIALS);
if (is_array($mysql_credentials)) {

  wh_log('==================[ Incoming Request ]==================');

  wh_log("Full _REQUEST dump:\n".print_r($_REQUEST,true)); 

  if ( !isset($_GET['key']) ){
    wh_log('No security key specified, ignoring request'); 
  } elseif ($_GET['key'] != WEBHOOKKEY) {
    wh_log('Security key specified, but not correct:');
    wh_log("\t".'Wanted: "' . WEBHOOKKEY . '", but received "'.$_GET['key'].'"');
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
  wh_log('Unable to process database credentials!');
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
