<?php
/**
 * Provides Service Now integration
 * This integration provides a sample scenario where a deployment triggers a standard change
 * closes the standard change tasks and then closes the standard change.
 * @Author Chris Cawley
 */
// Load credentials.
// See the README.md for instructions on storing secrets.
$secrets = _get_secrets(array('sn_url'));

$fields = array(
  array(
    'title' => 'Site',
    'value' => $_ENV['PANTHEON_SITE_NAME']
  ),
  array( // Render Environment name with link to site, <http://{ENV}-{SITENAME}.pantheon.io|{ENV}>
    'title' => 'Environment',
    'value' => '<http://' . $_ENV['PANTHEON_ENVIRONMENT'] . '-' . $_ENV['PANTHEON_SITE_NAME'] . '.pantheonsite.io|' . $_ENV['PANTHEON_ENVIRONMENT'] . '>'
  ),
  array( // Render Name with link to Email from Commit message
    'title' => 'By',
    'value' => $_POST['user_email']
  ),
  array( // Render workflow phase that the message was sent
    'title' => 'Workflow',
    'value' => ucfirst($_POST['stage']) . ' ' . str_replace('_', ' ',  $_POST['wf_type'])
  ),
  array(
    'title' => 'View Dashboard',
    'value' => '<https://dashboard.pantheon.io/sites/'. PANTHEON_SITE .'#'. PANTHEON_ENVIRONMENT .'/deploys|View Dashboard>'
  ),
);

switch($_POST['wf_type']) {
  case 'deploy':
    $deploy_tag = `git describe --tags`;
    // $deploy_message = $_POST['deploy_message'];
    $committer = `git log -2 --pretty=%cn`;
    $email = `git log -2 --pretty=%ce`;
    $message = `git log -3`;
    $hash = `git log -2 --pretty=%h`;
    $text = 'Deploy to the '. $_ENV['PANTHEON_ENVIRONMENT'];
    $text .= ' environment of '. $_ENV['PANTHEON_SITE_NAME'] .' by '. $_POST['user_email'] .' complete.';
    $text .= ' <https://dashboard.pantheon.io/sites/'. PANTHEON_SITE .'#'. PANTHEON_ENVIRONMENT .'/deploys|View Dashboard> ';
    $text .= $message;
    $todayStart = date("Y-m-d H:i:s");
    $todayEnd = date('Y-m-d H:i:s',strtotime('+1 minutes',strtotime($todayStart)));
    $fields[] = array(
      'title' => 'Details',
      'value' => $text
    );
    $fields[] = array(
      'title' => 'Deploy Note',
      'value' => $message
    );
    $fields[] = array(
        'title' => 'Commit',
        'value' => rtrim($hash)
    );
    $sn_attachment = array(
      'short_description' => 'Pantheon Change Record',
      'description' => $text,
      'start_date' => $todayStart,
      'end_date' => $todayEnd,
      'assignment_group' => 'Drupal Support',
      'assigned_to' => 'John Doe',
      'category' => 'DevOps',
      'requested_by' => $_POST['user_email']
    );
  break;

  default:
    $text = $_POST['qs_description'];
  break;
}

$close_code = array(
  'state' => '3',
  'close_notes' => 'Closed successfully',
  'close_code' => 'successful'
);

// Insert Service Now Change Record
switch($_ENV['PANTHEON_ENVIRONMENT']) {
  case 'test':
    _sn_notification($secrets['sn_url'], $sn_attachment, $close_code, $secrets['user'], $secrets['password']);
  break;
  case 'live':
    _sn_notification($secrets['sn_url'], $sn_attachment, $close_code, $secrets['user'], $secrets['password']);
  break;
  default:
    die('Environment condition not met. Skipping quicksilver actions.');
  break;
}


/**
 * Get secrets from secrets file.
 *
 * @param array $requiredKeys  List of keys in secrets file that must exist.
 */
function _get_secrets($requiredKeys)
{
  $secretsFile = $_SERVER['HOME'] . '/files/private/secrets.json';
  if (!file_exists($secretsFile)) {
    die('No secrets file found. Aborting!');
  }
  $secretsContents = file_get_contents($secretsFile);
  $secrets = json_decode($secretsContents, 1);
  if ($secrets == false) {
    die('Could not parse json in secrets file. Aborting!');
  }
  $missing = array_diff($requiredKeys, array_keys($secrets));
  if (!empty($missing)) {
    die('Missing required keys in json secrets file: ' . implode(',', $missing) . '. Aborting.');
  }
  return $secrets;
}

/**
 * Send a notification to Service Now
 */
function _sn_notification($url, $sn_attachment, $close_code, $user, $password)
{
  $todayStart = date("Y-m-d H:i:s");
  $todayEnd = date('Y-m-d H:i:s',strtotime('+1 minutes',strtotime($todayStart)));
  print($todayStart);
  print($todayEnd);
  $payload = json_encode($sn_attachment, JSON_UNESCAPED_SLASHES );
  $closecode = json_encode($close_code, JSON_UNESCAPED_SLASHES );
  print($url);
  print($closecode);
  print($payload);
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_TIMEOUT, 5);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
  curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
  curl_setopt($ch, CURLOPT_USERPWD, "$user:$password");
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  // Watch for messages with `terminus workflows watch --site=SITENAME`
  print("\n==== POST to Service Now ====");
  $result = curl_exec($ch);
  // print("RESULT: $result");
  $decodedResult = json_decode($result, true);
  print("\n===== Ticket ID = " . $decodedResult['result']['sys_id']['value']." =====");
  var_dump($decodedResult);
  $changeID = $decodedResult['result']['sys_id']['value'];
  print("\n===== POST Complete. =====");
  //reset the method and URL
  print("\n===== Reset Method to GET =====");
  curl_setopt($ch, CURLOPT_HTTPGET, 1);
  curl_setopt($ch, CURLOPT_URL, $sn_change . $changeID .'/task');
  $resultGET = curl_exec($ch);
  $decodedGET = json_decode($resultGET, true);
  print("\n===== Impliment Task ID = ".$decodedGET['result'][0]['sys_id']['value']);
  print("\n===== Test Task ID =  ".$decodedGET['result'][1]['sys_id']['value']);
  $implimentChange = $decodedGET['result'][0]['sys_id']['value'];
  $testChange = $decodedGET['result'][1]['sys_id']['value'];
  print("\n===== GET Complete. =====");
  //reset the method and URL
  print("\n===== Reset Method to PATCH =====");
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
  curl_setopt($ch, CURLOPT_POSTFIELDS, $closecode);
  curl_setopt($ch, CURLOPT_URL, $sn_change . $changeID . '/task'.'/' . $implimentChange);
  $resultPATCH1 = curl_exec($ch);
  $decodedPATCH1 = json_decode($resultPATCH1, true);
  // var_dump($decodedPATCH);
  print("\n===== Result: " . $decodedPATCH1['result']['close_notes']['value']." =====");
  print("\n===== PATCH 1 Complete. =====");
  print("\n===== Reset PATCH URL =====");
  curl_setopt($ch, CURLOPT_URL, $sn_change . $changeID . '/task'.'/' . $testChange);
  $resultPATCH2 = curl_exec($ch);
  $decodedPATCH2 = json_decode($resultPATCH2, true);
  // var_dump($resultPost2);
  print("\n===== Result: " . $decodedPATCH2['result']['close_notes']['value']." =====");
  print("\n===== PATCH 2 Complete. =====\n");
  curl_close($ch);
}