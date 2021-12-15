<?php


  /*

    This is a "standalone" version of the tracker, that you can view in your browser. The algorithms for tracking performance
    are the same as in the normal version. This will show all results, not just slowdowns. The normal version of the tracker
    integrates with slicie.com, which keeps track of "baseline performance", which sees how well the server typically performs.
    Without having a persistent knowledge of how well anything normally works, it's not possible to differentiate between
    optimal performance and a slowdown.

    This script is simply provided to allow people to more easily understand how the tracker works, it's not intended to be
    a replacement for the normal version.

    A completely standalone version the tracker is not viable, because on many hosting platforms, the tracker may be killed
    when a user exhausts all of their resources. By having another remote server monitor the success of the tracking,
    we are able to detect instances where the tracker itself is killed or aborted. This is fairly common on CloudLinux,
    as well as on web services that are configured to prevent longer running software.

  */

  $iofile = dirname(__FILE__) . "/performance-io.txt";
  $diskhandle = fopen($iofile, "w");

  if($diskhandle == FALSE){

    echo "<h2>Unable to set up the tracker</h2>Due to a permission issue, this script is unable to create the file performance-io.txt within the script's directory.";
    exit(0);

  }

  $memhandle = null;
  if(file_exists('/dev/zero')){

    $memhandle = fopen('/dev/zero', 'r');

    if ( !$memhandle ) {

      echo "Failed to open /dev";
      exit(0);

    }

  }


  if(version_compare(phpversion(), '7.1', '>=')) {
    ini_set( 'precision', 17 );
    ini_set( 'serialize_precision', -1 );
  }

  /*
    
    This software is intended to not cause an impact on the server's performance, and its calculations work best
    when running in the lowest possible priority. The goal is to be an "observer" of performance without influencing it.

  */

  proc_nice(19);

  if(function_exists('pcntl_setpriority')){

    pcntl_setpriority(19, null, 'PRIO_PGRP');
    pcntl_setpriority(19, null, 'PRIO_PROCESS');

  }

  if(is_shell_exec_available()){

    /*
      
      Not a well known approach - but interesting nonetheless. You set the CPU scheduler to only give time to this process
      when there is nothing else to do, by setting it to "IDLE".

      In the latest release of Ubuntu, this is neccessary, in spite of the NICE setting, because of process groups.

    */

    $pid = getmypid();
    echo exec("chrt -i -p 0 " . $pid);
    echo shell_exec("chrt -p " . $pid);

  }else{

    echo "exec is not allowed on this server";

  }
  echo "<hr>";

  $disk_payload = str_repeat("0", 256 * 1024);

  for($cpu_ct = 0; $cpu_ct < 240; $cpu_ct++){

    echo "<br><strong>" . date(DATE_RFC2822) . "</strong><br>\n";

    /*
  
      The purpose of the CPU test is to not cause any significant CPU usage, while also measuring the availability of the CPU.
      
      This tracker makes an effort to make itself run only when the kernel has nothing better to do. It may likely
      be the only thing on the server truly running with "idle" scheduling. The purpose of this approach is to ensure
      that, when the server has something better to do, it's doing that instead. The script will use about 1% of a CPU core,
      and this approach causes it to use so little, under contention, that it likely will not show up in "top".

      The CPU test uses very little CPU time, and we only track the performance for 1/20th of a second at a time, sleeping
      for 1/20th of a second after each moment of tracking.

      We track the performance, in the sense that we're watching the availability of the CPU. We don't know the severity of
      a slowdown (and we don't show any such calculation in the UI), but we do know that the kernel struggled to give
      CPU time to our process. Considering that our process uses about 1% of a 1 core, and that it essentially does nothing but sleep,
      that's a strong indicator that there is contention on the CPU.

      The main way we see the level of contention is by indirectly measuring how long it takes to do a short sleep. This process
      will switch over to the kernel when sleeping, and the kernel will do whatever more important work needs to be done.
      Once the kernel has free CPU time available, it will switch back to this process. We're asking it to sleep for 10 microseconds,
      which is a very short amount of time, and we can expect that, when there is CPU time available, this will complete after the same
      amount of delay in a consistent way, depending on the kernel and clock speed of the CPU. We don't judge the speed of this
      in any "absolute" terms, it's done relative to the "baseline performance" of this individual platform. We're merely comparing
      how quickly it does this compared to previous times it has done the same thing, and we're flagging 2X slowdowns or worse.


      The results of this test are far more sensitive to problems than any common "monitoring" or statistics software. Most
      statistics are aggregated over 5 minutes, but even frequent statistics use a 60 second interval. Typical configurations
      of software like Nagios do not have the ability to represent such precise slowdowns. This software reports slowdowns that
      are sub-second, if they are severe enough.

      It is expected that most server administrators will look at say, 40% CPU usage in a 1-5 minute average, and infer that there are
      *never* slowdowns related to CPU. The reality is, and this performance tracker demonstrates this, that demand for the CPU
      is not consistent over time. In particular, on Linux systems with cron jobs (which is to say, practically ever one), much
      of the demand for the CPU is accumulated on the start of every minute. Even without these scheduled tasks, on-demand applications,
      like every web app, consume CPU time as requests come in. This causes CPU usage to not be distributed evenly over time, and
      it's easy to imagine that CPU time becomes unavailable momentarily, when the average is as high as 40% (in the example).


      Beyond that, this approach allows you to see the slowdowns imposed outside of your virtual environment. At a "shared hosting"
      level, solutions like CloudLinux attempt to "isolate" the user from the other usage on the server. As a user, you may not be able
      to determine, from statistics accessible to your user, that there are any issues. The simplicity of this test is also its strongest
      benefit, in that, no matter what CloudLinux reports, if there is no CPU time available on the server, it will slow down in this test.

      Similarly, you have very little means of detecting if there is a lack of CPU time available from within a virtual machine. Linux
      has a concept of "CPU Steal", but in order to collect that data, you must first have CPU time stolen from you. The only mechanism
      you available to have time stolen, is to try to consume it. From our own testing, we find that, not all physical servers are loaded
      to the same degree of CPU demand. You can 

    */


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

    echo "CPU: " . $iterations . "<br>\n";

    

    /*

      Here we measure the performance of memory by simply reading from /dev/null
      We read a very small amount of data (for memory), typically 16MiB, and we see how quickly it can do it.

      On bare metal servers, this only shows as a slowdown when memory is swapped to disk.
      On shared hosting servers, this can be a frequent source of slowdowns, when there performance of the app is limited.

      In particular, CloudLinux will hit this a lot, especially when the user is hitting many faults.

    */

    // Measuring how quickly we can read from /dev/null

    $memory_read_start = get_hires_seconds();
    $read_bytes = fread($memhandle, 16 * 1024 * 1024);
    $memory_read_end = get_hires_seconds();
    $read_bytes = null;

    $mem_duration = ($memory_read_end - $memory_read_start) * 1000;


    echo "Memory: " . $mem_duration . "<br>\n";

    
    /*
      
      This simply writes a small amount of data (256K) to the disk, and sees how quickly it does it.

      The biggest reason this test will show as a slowdown is if the IO queue starts piling up. When there isn't a big IO queue,
      there isn't enough of an added delay (we are looking for 5X or worse), to show a slowdown.

      When the server is backed by consumer grade SSDs (or worse, spinning disks), this will be a frequent source of slowdowns.

      Ultimately though, it's important to understand whether or not your application needs consistent write IO.
      If your application is very "read heavy", and almost never writes to the disk, this might not be a relevant metric.

      With that said, most applications do write to the disk, even momentarily for things like sessions (though their are
      in-memory database alternatives to that), so for severe slowdowns (they can be many magnitudes slower), it still has an impact.

    */



    // This tests the latency of writing a small amount of data to the storage subsystem

    rewind($diskhandle);  // Go back to byte 0
    $disk_write_start = get_hires_seconds();
    fwrite($diskhandle, $disk_payload);  // Write to the filehandle
    fflush($diskhandle);  // Flush it, this is critical
    $disk_write_end = get_hires_seconds();
    ftruncate($diskhandle, ftell($diskhandle)); // Make the file 0 bytes again


    $disk_duration = ($disk_write_end - $disk_write_start) * 1000;

    echo "Disk: " . $disk_duration . "<br>\n";

  


    if(ob_get_level() > 0) ob_flush();
    flush();
    usleep(1000 * 1000);

  }

  fclose($memhandle);
  fclose($diskhandle);

  if(file_exists($iofile)){

    unlink($iofile);

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


  function buffer_object($obj){

    echo json_encode($obj);

    if(ob_get_level() > 0) ob_flush();
    flush();

  }


?>