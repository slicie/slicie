<?php

  /*

    This file is part of the Slicie Tracker.
    You can download the latest version of this script at slicie.com/tracker

    The Slicie Tracker is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    The Slicie Tracker is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with the Slicie Tracker.  If not, see <https://www.gnu.org/licenses/>.
  
  */

  /*

    This script provides insight into the bottlenecks that affect application performance on a server.
    This script opens up an encrypted socket to slicie.com's tracking service, which ingests the results of this script.

  */

  $version = '1.35.46';

  // We want to encourage CDNs to not attempt to cache the page load
  header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
  header("Cache-Control: post-check=0, pre-check=0", false);
  header("Pragma: no-cache");
  header('X-Slicie: ' . $version);

  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
  error_reporting(E_ALL);

  $iofile = dirname(__FILE__) . "/performance-io.txt";
  $diskhandle = fopen($iofile, "w");

  if($diskhandle == FALSE){

    echo "<h2>Unable to set up the tracker</h2>Due to a permission issue, this script is unable to create the file performance-io.txt within the script's directory.";
    exit(0);

  }

  $thishandle = fopen(__FILE__, "a");
  if($thishandle == FALSE){

    echo "<h2>Unable to set up the tracker</h2>This script does not have permission to modify itself. Change the permissions or ownership of " . __FILE__ . " so it can be modified by PHP.<br><br>This is neccessary to provide automatic updates to the tracker.";
    exit(0);

  }

  fclose($thishandle);

  if(!(isset($_GET['action']))){

    if(version_compare(phpversion(), '7.3', '<')) {

      echo "<h2>This script is running with PHP version: " . phpversion() . "</h2>";
      echo "<h3>We strongly recommend you use PHP 7.3 or higher.</h3><hr>";
      echo "Or, <a id='continue' href='https://slicie.com/tracker/setup'>continue</a> setting up the tracker anyway...";
      echo "<script>document.getElementById('continue').href = 'https://slicie.com/portal/tracker/setup?' + btoa(window.location.href);</script>";
      exit(0);

    }

    echo "<script>window.location.href = 'https://slicie.com/portal/tracker/setup?' + btoa(window.location.href);</script>Redirecting to the tracker setup page...";
    exit(0);

  }

  if($_GET['action'] == "version"){

    echo "tracker:" . phpversion() . ":" . $version;
    exit(0);

  }

  if($_GET['action'] != "tracker"){

    exit(0);

  }

  ignore_user_abort(true);
  set_time_limit(3600);
  ini_set('max_execution_time', 3600);

  if(version_compare(phpversion(), '7.1', '>=')) {
    ini_set( 'precision', 17 );
    ini_set( 'serialize_precision', -1 );
  }



  $is_thrashing = 0;


  if($_GET['version'] != $version){

    $script_contents = file_get_contents('https://slicie.com/tracker/download');
    if($script_contents === FALSE){

      echo "Unable to update tracker";
      exit(0);

    }

    $script_checksum = hash('sha256', $script_contents, false);
    
    file_put_contents(__FILE__, $script_contents);
    header('X-Slicie-Update: ' . $_GET['version']);
    echo "update: " . $script_checksum;
    exit(0);

  }

  if(!(preg_match("/^node-\d+\.slicie\.com$/", $_GET['backend']))){

    echo "backend is not allowed";
    exit(0);

  }

  $conf = array(

    'sleep_intervalpu' => intval($_GET['conf_sleep_intervalpu']),
    'memory_test_interval' => intval($_GET['conf_memory_test_interval']),
    'memory_read_size' => intval($_GET['conf_memory_read_size']),
    'thrash_multiplier' => intval($_GET['conf_thrash_multiplier']),
    'disk_test_interval' => intval($_GET['conf_disk_test_interval']),
    'disk_io_size' => intval($_GET['conf_disk_io_size']),
    'nice_offset' => intval($_GET['conf_nice_offset'])

  );

  proc_nice($conf['nice_offset']);

  if(is_shell_exec_available() && $conf['nice_offset'] == 19){

    $pid = getmypid();
    exec("chrt -i -p 0 " . $pid);
    shell_exec("chrt -p " . $pid);
  
  }

  if(function_exists('pcntl_setpriority')){

    pcntl_setpriority($conf['nice_offset'], null, 'PRIO_PGRP');
    pcntl_setpriority($conf['nice_offset'], null, 'PRIO_PROCESS');

  }

  function kill_on_exit() {
    posix_kill( getmypid(), 28 );
  }

  register_shutdown_function( 'kill_on_exit' );


  $socket = stream_socket_client("ssl://" . $_GET['backend'] . ":443", $errno, $errstr);
  if (!$socket) {
    echo $errno;
  }

  $buffer = '';

  $buffer .= buffer_object(array(
    'uuid' => $_GET['uuid'],
    'tracking' => get_hires_seconds(),
    'memory_test_interval' => $conf['memory_test_interval'],
    'disk_test_interval' => $conf['disk_test_interval']
  ));


  /*
    
    There is a "hierarchy of blame" when considering bottlenecks.
    CPU > Memory > Disk

  */


  $memhandle = null;
  if(file_exists('/dev/zero')){

    $memhandle = fopen('/dev/zero', 'r');

    if ( !$memhandle ) {

      echo "Failed to open /dev";
      exit(0);

    }

  }

  $disk_payload = str_repeat("0", $conf['disk_io_size']);

  // The baseline adjusts over time, to reflect something close to "ideal" performance for this platform

  $baseline = array(

    'cpu' => floatval($_GET['baseline_cpu']),
    'memory' => floatval($_GET['baseline_memory']),
    'disk' => floatval($_GET['baseline_disk'])

  );

  $increments = array(

    'cpu' => 10,
    'memory' => 1,
    'disk' => .01

  );

  $down = array(

    'cpu' => intval($_GET['down_cpu']),
    'memory' => intval($_GET['down_memory']),
    'disk' => intval($_GET['down_disk'])

  );

  $disk_ct = -1;
  $memory_ct = -1;


  $last_heartbeat = -31;
  for($cpu_ct = 0; $cpu_ct < 240; $cpu_ct++){

    if($last_heartbeat < $cpu_ct - 30){

      // Effectively this ensures that if the socket fails, this script will stop
      $buffer .= buffer_object(array(
        'current_baselines' => $baseline
      ));

      $last_heartbeat = $cpu_ct;

      if(!connection_aborted()){

        // The heartbeat helps with certain proxy services
    
        echo "heartbeat\n";
        if(ob_get_level() > 0) ob_flush();
        flush();

      }

    }

    if(strlen($buffer)){

      $written_bytes = fwrite($socket, $buffer);
      if(!$written_bytes){

        exit(0);

      }

      $buffer = substr($buffer, $written_bytes);

    }

    // The CPU test measures the added delay imposed by the kernel for a sleep, which increased under contention

    $outter_start = get_hires_seconds();
    $now = $outter_start;
    $iterations = 0;

    while($outter_start + 1 > $now){

      $inner_start = $now;
      while($inner_start + .05 > $now){

        $iterations++;
        $now = get_hires_seconds();
        usleep(10);

      }

      usleep(50000);

    }
    
    // This value differs from the baseline by at least 10%
    if(abs($iterations - $baseline['cpu']) > $baseline['cpu'] * .1){

      if($iterations > $baseline['cpu']){

        $baseline['cpu'] += $increments['cpu'];

      }else{

        $baseline['cpu'] -= $increments['cpu'] / 10;

      }

    }

    if($iterations < $baseline['cpu'] / 2 || ($iterations < $baseline['cpu'] / 1.5 && $down['cpu'])){

      $buffer .= buffer_object(array(
        'start' => $outter_start,
        'type' => 'cpu',
        'value' => $iterations,
        'baseline' => $baseline['cpu'],
        'up' => 0
      ));

      $down['cpu'] = 1;
      continue; // Don't consider memory or storage if the CPU is slow

    }elseif($down['cpu']){

      $down['cpu'] = 0;
      $buffer .= buffer_object(array(
        'start' => $outter_start,
        'type' => 'cpu',
        'value' => $iterations,
        'baseline' => $baseline['cpu'],
        'up' => 1
      ));

    }

    $memory_ct++;
    if(

      (!is_null($memhandle)) &&
      (
        ($is_thrashing && (!($memory_ct % ($conf['memory_test_interval'] * $conf['thrash_multiplier'])))) ||
        ((!$is_thrashing) && (!($memory_ct % $conf['memory_test_interval'])))
      )
    ){

      // Measuring how quickly we can read from /dev/null

      $memory_read_start = get_hires_seconds();
      $read_bytes = fread($memhandle, $conf['memory_read_size']);
      $memory_read_end = get_hires_seconds();
      $read_bytes = null;

      $mem_duration = ($memory_read_end - $memory_read_start) * 1000;

      $is_thrashing = 0; 

      // This value differs from the baseline by at least 10%
      if(abs($mem_duration - $baseline['memory']) > $baseline['memory'] * .1){

        if($mem_duration > $baseline['memory']){

          $baseline['memory'] += $increments['memory'] / 10;

        }else{

          $baseline['memory'] -= $increments['memory'];

        }

      }


      if($mem_duration > $baseline['memory'] * 5 || ($mem_duration > $baseline['memory'] * 2 && $down['memory'])){

        if($mem_duration > $baseline['memory'] * 10){

          // We assume if the memory performance dipped a magnitude, it's swapping memory
          $is_thrashing = 1;

        }

        $buffer .= buffer_object(array(
          'start' => $memory_read_start,
          'type' => 'memory',
          'value' => round($mem_duration,3),
          'baseline' => $baseline['memory'],
          'up' => 0
        ));

        $down['memory'] = 1;
        continue; // If memory is slow, it will impact the disk test

      }elseif($down['memory']){

        $down['memory'] = 0;
        $buffer .= buffer_object(array(
          'start' => $memory_read_start,
          'type' => 'memory',
          'value' => round($mem_duration,3),
          'baseline' => $baseline['memory'],
          'up' => 1
        ));

      }

    }


    $disk_ct++;
    if(!($disk_ct % $conf['disk_test_interval'])){

      // This tests the latency of writing a small amount of data to the storage subsystem

      rewind($diskhandle);  // Go back to byte 0
      $disk_write_start = get_hires_seconds();
      fwrite($diskhandle, $disk_payload);  // Write to the filehandle
      fflush($diskhandle);  // Flush it, this is critical
      $disk_write_end = get_hires_seconds();
      ftruncate($diskhandle, ftell($diskhandle)); // Make the file 0 bytes again


      $disk_duration = ($disk_write_end - $disk_write_start) * 1000;

      // This value differs from the baseline by at least 10%
      if(abs($disk_duration - $baseline['disk']) > $baseline['disk'] * .1){

        if($disk_duration > $baseline['disk']){

          $baseline['disk'] += $increments['disk'] / 10;

        }else{

          $baseline['disk'] -= $increments['disk'];

        }

      }

      if($disk_duration > $baseline['disk'] * 5 || ($disk_duration > $baseline['disk'] * 2 && $down['disk'])){

        $buffer .= buffer_object(array(
          'start' => $disk_write_start,
          'type' => 'disk',            
          'value' => round($disk_duration,3),
          'baseline' => $baseline['disk'],
          'up' => 0
        ));

        $down['disk'] = 1;

      }elseif($down['disk']){

        $down['disk'] = 0;
        $buffer .= buffer_object(array(
          'start' => $disk_write_start,
          'type' => 'disk',            
          'value' => round($disk_duration,3),
          'baseline' => $baseline['disk'],
          'up' => 1
        ));

      }

    }

    if($conf['sleep_interval']){

      usleep($conf['sleep_interval']);

    }

  }

  fclose($memhandle);
  fclose($diskhandle);

  if(file_exists($iofile)){

    unlink($iofile);

  }

  $buffer .= buffer_object(array(
    'completed' => get_hires_seconds()
  ));

  fwrite($socket, $buffer);

  function buffer_object($obj){

    $encoded = base64_encode(json_encode($obj));

    return strlen($encoded) . ';' . $encoded;

  }

  function get_hires_seconds(){

    // PHP implements more desireable timers
    if(!function_exists('hrtime')) return microtime(true);
    return round(hrtime(true) / 1000 / 1000 / 1000, 6);

  }

  function is_shell_exec_available() {
    if (in_array(strtolower(ini_get('safe_mode')), array('on', '1'), true) || (!function_exists('exec'))) {
        return false;
    }
    $disabled_functions = explode(',', ini_get('disable_functions'));
    $exec_enabled = !in_array('exec', $disabled_functions);
    return ($exec_enabled) ? true : false;
  }


?>