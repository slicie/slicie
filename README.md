
# Slicie Performance Tracker

This PHP script tracks the speed of your app's platform and identifies the cause of slowdowns in real-time.
This software tracks platform performance, and _is not_ statistics software like Nagios or NewRelic.

> [Research shows](https://www.portent.com/blog/analytics/research-site-speed-hurting-everyones-revenue.htm) conversions drop 4.42% every second your site loads. If you have an online business, performance slowdowns can be very costly. This tracker finds bottlenecks in any hosting platform.

## How it works
 - Tracking for CPU, Memory, and Storage
 - No performance impact
 - Supports any platform and application
 - Simple setup in minutes
 - Real-time and past, searchable, results
 - Notifications and daily reports

## Easy to understand graphs
Slowdowns for each individual resource are tracked and saved for later viewing.

![CPU Usage](https://slicie.com/tracker/image?title=1&metric=cpu&view=reliability&labels=1&title=1&end=5465102&start=5464814&token=dc0ff4c7799b0cf9&tracker=d4d0fe8053e5b42e&width=848&tz=America/Phoenix)

![Memory Usage](https://slicie.com/tracker/image?title=1&metric=memory&view=reliability&labels=1&title=1&end=5465102&start=5464814&token=dc0ff4c7799b0cf9&tracker=d4d0fe8053e5b42e&width=848&tz=America/Phoenix)

![Storage Usage](https://slicie.com/tracker/image?title=1&metric=disk&view=reliability&labels=1&title=1&end=5465102&start=5464814&token=dc0ff4c7799b0cf9&tracker=d4d0fe8053e5b42e&width=848&tz=America/Phoenix)

## Installing the tracker manually
Simply upload the tracker's PHP script to your server so that it can be accessed through the web. You can [download the stable release](https://slicie.com/tracker/download) and upload it to your website with an FTP client. Or you can use either of the commands below with SSH.

```curl "https://slicie.com/tracker/slicie-tracker.php" --output slicie-tracker.php```
or use wget:
```wget "https://slicie.com/tracker/slicie-tracker.php"```

**After uploading the tracker, access the script in your web browser to configure it.**

## Installing with Docker
You can set up the tracker on your server easily with docker. We have a hub.docker.com repository built off of a simple Apache installation.
Your performance is tracked just as reliably within a docker container as it will through any other method of installation.

```
docker pull slicie/tracker
docker run -d -p 8888:80 --name tracker slicie/tracker
```

You can modify 8888 in the docker run statement to change the publicly accessible port. You will connect to the tracker over http and not https; however, your tracking statistics are communicated to the slicie network over HTTPs.

**The tracker will be found at the URI /slicie-tracker.php**

To set up the tracker, you need to view slicie-tracker.php in your browser. You can access it using the public IP of your docker container (or its hostname) and its port.
In the example, using port 8888, you would connect to the tracker using the following URL:
http://example.com:8888/slicie-tracker.php
You can also use the IP of the server, like this:
http://1.2.3.4:8888/slicie-tracker.php

## Requirements
 - The tracker has minimal impact on performance, but uses just 2% of 1 CPU core.
 - Shared hosting compatible: no root/admin access required.
 - Any Linux release (e.g. Ubuntu, RedHat, CloudLinux, etc) or Windows* (w/o memory tracking)
 - PHP 5+ minimum with any web server (e.g. Apache, LiteSpeed, NGINX, IIS, etc).
 - PHP 7.3 recommended for higher resolution timers.

## What the tracker does not do
The tracker does not look at how quickly your website loads or pay attention to its "uptime". There are many services, like "Pingdom", which do that. The tracker also does not hook into your application's code, like "NewRelic".

The tracker uses simple, stable, algorithms that track the performance of individual resources, but it does not look at things like the quantity of available memory, the amount of CPU usage, or the server load.

The tracker is not designed to tell you if your application is optimized or performing well, it is designed to track whether or not the platform your application is running on is performing properly.
