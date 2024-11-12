# PVTL Website Downtime Logger

### Plugin Summary for Website Server and Downtime Monitor

**Plugin Name**: Website Server and Downtime Monitor  
**Description**: A robust monitoring tool designed to track website uptime, server resources, and performance issues, helping you stay on top of your server’s health and responsiveness.

#### Key Features:

1. **Website Downtime Detection**:  
   - Continuously monitors your website's availability, logging any downtime events and alerting you if the site becomes unreachable.

2. **Resource Usage Monitoring**:  
   - Tracks CPU, memory, and I/O usage in real-time, and sends alerts when server resource usage crosses set thresholds, indicating potential overload or performance degradation.
   - Customisable resource thresholds for CPU, memory, and I/O allow you to set limits based on your server’s capacity and usage needs.

3. **Detailed Log Viewer**:  
   - Provides a structured, easy-to-read interface for reviewing historical logs of server and site status.
   - Includes a table view of logged CPU, memory, and I/O usage spikes, as well as HTTP errors categorised into 3XX, 4XX, and 5XX statuses, enabling quick analysis of resource spikes and error patterns.

4. **Top IPs and Bot Detection**:  
   - Displays the top 25 IP addresses accessing your site, along with the number of requests each IP makes, helping you identify potential sources of high traffic or misuse.
   - Detects common bots, crawlers, and spiders by their user-agent strings, giving insight into automated traffic.

5. **Automated Bot Blocking**:
   - Allows you to easily block selected bots directly from the log viewer. The plugin automatically adds blocking rules to your `.htaccess` file to prevent identified bots from accessing your site, reducing unwanted traffic and preserving server resources.

6. **Customisable Alert System**:  
   - A configurable alert interval prevents redundant notifications, ensuring you’re only alerted when necessary.
   - Email alerts inform you of any significant events, such as downtime or resource spikes, allowing for a quick response to issues.

7. **Cron-Based Monitoring**:
   - The plugin runs a monitoring check every minute using WordPress’ cron system, ensuring real-time oversight of your site’s health.
   - Easy integration into the WordPress dashboard with a log viewer accessible under the Tools menu, providing quick access to historical data and monitoring results.

#### Technical Details:

- **Plugin Requirements**: Compatible with WordPress sites on servers supporting `.htaccess` modifications.
- **Setup**: Simply install and activate; the plugin automatically begins monitoring and logging.
- **Data Security**: Logs are stored locally within the WordPress environment, and bot-blocking is handled directly within your site’s `.htaccess` file, ensuring minimal overhead.

This plugin is ideal for users who need a reliable, comprehensive monitoring solution for uptime and server resources, with built-in analysis and bot management tools to maintain site performance and security. Whether you’re managing a single website or a larger network, the Website Server and Downtime Monitor keeps you informed and empowered to take proactive steps against potential issues.

## Installation into a Bedrock site

#### Install

```
# 1. Get it ready (to use a repo outside of packagist)
composer config repositories.pvtl-logger git https://github.com/pvtl/pvtl-logger

# 2. Install the Plugin
composer require pvtl/pvtl-logger
```

#### Activate / Configure

- Activate the plugin