<?php
chdir(dirname(__FILE__).'/..');
require 'vendor/autoload.php';


$users = ORM::for_table('users')
  ->where('micropub_success', 1)
  ->where_not_null('instagram_username');

if(isset($argv[1])) {
  if(is_numeric($argv[1])) {
    $users = $users->where('tier',$argv[1]);
  } else {
    $users = $users->where('instagram_username',$argv[1]);
  }
}

$users = $users->find_many();

if(count($users)) {
  echo "========================================\n";
  echo date('Y-m-d H:i:s') . "\n";
  echo "Processing " . (is_numeric($argv[1]) ? "Tier" : "User") . " " . $argv[1] . "\n";
  echo count($users)." Users\n";
}

foreach($users as $user) {

  try {

    $feed = IG\get_user_photos($user->instagram_username);

    if(!$feed) {
      $user->tier = $user->tier - 1;
      log_msg("Error retrieving user's Instagram feed. Demoting to ".$user->tier, $user);
      $user->save();
      continue;
    }

    $micropub_errors = 0;

    foreach($feed['items'] as $item) {
      $url = $item['url'];

      // Skip any photos from before the cron task was launched
      if(strtotime($item['published']) < strtotime('2016-05-31T14:00:00-0700')) {
        continue;
      }

      $photo = ORM::for_table('photos')
        ->where('user_id', $user->id)
        ->where('instagram_url', $url)
        ->find_one();

      // Check if this photo has already been imported.
      // The photo may already be in the DB, but not have been processed yet.
      if(!$photo || !$photo->processed) {
        if(!$photo) {
          $photo = ORM::for_table('photos')->create();
          $photo->user_id = $user->id;
          $photo->instagram_url = $url;
        }

        $entry = h_entry_from_photo($url);

        $photo->instagram_data = json_encode($entry);
        $photo->instagram_img = $entry['photo'];
        $photo->published = date('Y-m-d H:i:s', strtotime($entry['published']));
        $photo->save();

        // Post to the Micropub endpoint
        $filename = download_file($entry['photo']);

        if(isset($entry['video'])) {
          $video_filename = download_file($entry['video']);
        } else {
          $video_filename = false;
        }

        // Collapse category to a comma-separated list if they haven't upgraded yet
        if($user->send_category_as_array != 1) {
          if($entry['category'] && is_array($entry['category']) && count($entry['category'])) {
            $entry['category'] = implode(',', $entry['category']);
          }
        }

        $rules = ORM::for_table('syndication_rules')->where('user_id', $user->id)->find_many();
        $syndications = '';
        foreach($rules as $rule) {
          if($rule->match == '*' || stripos($entry['content'], $rule->match) !== false) {
            if(!isset($entry['mp-syndicate-to']))
              $entry['mp-syndicate-to'] = [];
            $entry['mp-syndicate-to'][] = $rule->syndicate_to;
            $syndications .= ' +'.$rule->syndicate_to_name;
          }
        }

        log_msg("Sending ".($video_filename ? 'video' : 'photo')." ".$url." to micropub endpoint: ".$user->micropub_endpoint.$syndications, $user);

        $response = micropub_post($user->micropub_endpoint, $user->micropub_access_token, $entry, $filename, $video_filename);
        unlink($filename);

        $user->last_micropub_response = json_encode($response);
        $user->last_instagram_photo = $photo->id;
        $user->last_photo_date = date('Y-m-d H:i:s');

        if($response && isset($response['headers']['Location']) && ($response['code'] == 201 || $response['code'] == 202)) {
          $photo_url = $response['headers']['Location'][0];
          $user->last_micropub_url = $photo_url;
          $user->last_instagram_img_url = $entry['photo'];
          $user->photo_count = $user->photo_count + 1;
          $user->photo_count_this_week = $user->photo_count_this_week + 1;

          $photo->canonical_url = $photo_url;
          log_msg("Posted to ".$photo_url, $user);
        } else {
          // Their micropub endpoint didn't return a location, notify them there's a problem somehow
          log_msg("There was an error posting this photo. Response code was: ".$response['code'], $user);
          $micropub_errors++;
          if($response['code'] == 403) {
            break;
          }
        }
        $photo->processed = 1;
        $photo->save();

        $user->save();
      }

    }

    // After importing this batch, look at the user's posting frequency and determine their polling tier.
    if($micropub_errors > 0) {
      // Micropub errors demote the user to a lower tier. 
      // If they're already at the lowest tier, this will disable polling their account until they log back in.
      $user->tier = $user->tier - 1;
      log_msg("Encountered a Micropub error. Demoting to tier ".$user->tier, $user);
      $user->save();
    } else {
      // Check how many photos they've taken in the last 14 days
      $previous_tier = $user->tier;

      $count = ORM::for_table('photos')
        ->where('user_id', $user->id)
        ->where_gt('published', date('Y-m-d H:i:s', strtotime('-14 days')))
        ->count();
      if($count >= 7) {
        $user->tier = 4;
      } elseif($count >= 4) {
        $user->tier = 3;
      } elseif($count >= 2) {
        $user->tier = 2;
      } else {
        $user->tier = 1;
      }

      if($previous_tier != $user->tier) {
        if($user->tier > $previous_tier)
          $action = 'Upgrading';
        else
          $action = 'Demoting';
        log_msg($action . ' user to tier ' . $user->tier, $user);
        $user->save();
      }
    }

  } catch(Exception $e) {
    // Bump down a tier on errors
    $user->tier = $user->tier - 1;
    log_msg("There was an error processing this user. Demoting to tier ".$user->tier, $user);
    $user->save();
  }
}

function log_msg($msg, $user) {
  echo date('Y-m-d H:i:s ');
  if($user)
    echo '[' . $user->url . '] ';
  echo $msg . "\n";
}

