<?php
/**
 * Provides Microsoft Teams integration.
 * @Author Chris Cawley
 */
// Adaptive card constants
$contentType = "application/vnd.microsoft.card.adaptive";
$contentUrl = null;
$cardType = "AdaptiveCard";
$version = "1.4";

// Load credentials.
// See the README.md for instructions on storing secrets.
$secrets = _get_secrets(array('teams_url'));

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

// Customize the message based on the workflow type.
// must appear in your pantheon.yml for each workflow type you wish to send notifications on.
switch($_POST['wf_type']) {
  case 'deploy':
    // Find out what tag we are on and get the annotation.
    $deploy_tag = `git describe --tags`;
    $deploy_message = $_POST['deploy_message'];
    $committer = `git log -1 --pretty=%cn`;
    $email = `git log -1 --pretty=%ce`;
    $message = `git log -1 --pretty=%B`;
    $hash = `git log -1 --pretty=%h`;
    $text = 'Deploy to the '. $_ENV['PANTHEON_ENVIRONMENT'];
    $text .= ' environment of '. $_ENV['PANTHEON_SITE_NAME'] .' by '. $_POST['user_email'] .' complete!';
    $text .= ' <https://dashboard.pantheon.io/sites/'. PANTHEON_SITE .'#'. PANTHEON_ENVIRONMENT .'/deploys|View Dashboard>';
    $fields[] = array(
      'title' => 'Details',
      'value' => $text
    );
    $fields[] = array(
      'title' => 'Deploy Note',
      'value' => $deploy_message
    );
    $fields[] = array(
        'title' => 'Commit',
        'value' => rtrim($hash)
    );
  break;

  case 'sync_code':
    // Get the committer, hash, and message for the most recent commit.
    $committer = `git log -1 --pretty=%cn`;
    $email = `git log -1 --pretty=%ce`;
    $message = `git log -1 --pretty=%B`;
    $hash = `git log -1 --pretty=%h`;
    $text = 'Code sync to the ' . $_ENV['PANTHEON_ENVIRONMENT'] . ' environment of ' . $_ENV['PANTHEON_SITE_NAME'] . ' by ' . $_POST['user_email'] . "!\n";
    $text .= 'Most recent commit: ' . rtrim($hash) . ' by ' . rtrim($committer) . ': ' . $message;
    $fields[] = array(
        'title' => 'Commit',
        'value' => rtrim($hash)
    );
  break;

  case 'sync_code_with_build':
    // Get the committer, hash, and message for the most recent commit.
    $committer = `git log -1 --pretty=%cn`;
    $email = `git log -1 --pretty=%ce`;
    $message = `git log -1 --pretty=%B`;
    $hash = `git log -1 --pretty=%h`;
    $text = 'Code sync to the ' . $_ENV['PANTHEON_ENVIRONMENT'] . ' environment of ' . $_ENV['PANTHEON_SITE_NAME'] . ' by ' . $_POST['user_email'] . "!\n";
    $text .= 'Most recent commit: ' . rtrim($hash) . ' by ' . rtrim($committer) . ': ' . $message;
    $fields[] = array(
        'title' => 'Commit',
        'value' => rtrim($hash)
    );
  break;

  case 'clear_cache':
    $message = "";
    $fields[] = array(
      'title' => 'Cleared caches',
      'value' => 'Cleared caches on the ' . $_ENV['PANTHEON_ENVIRONMENT'] . ' environment of ' . $_ENV['PANTHEON_SITE_NAME'] . "!\n"
    );
  break;

  default:
    $text = $_POST['qs_description'];
  break;
}

$body = array(
  array(
  'type' => 'FactSet',
  'facts' => $fields
  )
);
$body[] = array(
  'type' => 'TextBlock',
  'text' => $message,
  'wrap' => true
);

$content = array(
  'type' => 'AdaptiveCard',
  'body' => $body,
  "version" => $version
);

$attachment = array(
  'contentType' => $contentType,
  'contentUrl' => $contentUrl,
  'content' => $content
);

_teams_notification($secrets['teams_url'], $attachment);


/**
 * Get secrets from secrets file.
 * Include your secrets.json file in the private directory. Do not include it in source control.
 * https://pantheon.io/docs/private-paths
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
  // Check for the required key, fail if not in secrets.json
  $missing = array_diff($requiredKeys, array_keys($secrets));
  if (!empty($missing)) {
    die('Missing required keys in json secrets file: ' . implode(',', $missing) . '. Aborting!');
  }
  return $secrets;
}

/**
 * Send a notification to MS Teams
 */
function _teams_notification($teams_url, $attachment)
{
  $post = array(
    'type' => 'message',
    'attachments' => array($attachment)
  );
  $payload = json_encode($post, JSON_UNESCAPED_SLASHES );
  print($payload);
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $teams_url);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_TIMEOUT, 5);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
  curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  // Watch for messages with `terminus workflows watch --site=SITENAME`
  print("\n==== Posting to Teams ====\n");
  $result = curl_exec($ch);
  print("RESULT: $result");
  print("\n===== wf_type posted: =====\n");
  print($_POST['wf_type']);
  // $payload_pretty = json_encode($post,JSON_PRETTY_PRINT); // Uncomment to debug JSON
  // print("JSON: $payload_pretty"); // Uncomment to Debug JSON
  print("\n===== Post Complete! =====\n");
  curl_close($ch);
}
