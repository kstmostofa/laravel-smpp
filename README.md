PHP SMPP (v3.4) client
====

Install:

    composer require kstmostofa/laravel-smpp

Laravel Integration
-------------------

This package supports Laravel's package auto-discovery. After installing, you can use the `LaravelSmpp` facade.


### Configuration

You can publish the configuration file using the following command:

```bash
php artisan vendor:publish --provider="Kstmostofa\\LaravelSmpp\\LaravelSmppServiceProvider" --tag="smpp-config"
```

This will create a `config/smpp.php` file in your application, which you can modify to set your SMPP server details and other options.

Alternatively, you can set the following environment variables in your `.env` file:

```
SMPP_HOST=127.0.0.1
SMPP_PORT=2775
SMPP_USERNAME=smppuser
SMPP_PASSWORD=smpppass
SMPP_TIMEOUT=10000
SMPP_DEBUG=false
```

### Usage

This package provides a Facade for convenient access to the `SmppClient`.

```php
use Kstmostofa\LaravelSmpp\Facades\LaravelSmpp;
use Kstmostofa\LaravelSmpp\SMPP;

// To send an SMS with method chaining and request a delivery receipt
$messageId = LaravelSmpp::setSender('SENDER', SMPP::TON_ALPHANUMERIC)
    ->setRecipient('2348112291137', SMPP::TON_INTERNATIONAL)
    ->requestDLR() // Request a Delivery Receipt
    ->sendSMS('We are here for the meeting now. HH9 12pm', null, SMPP::DATA_CODING_DEFAULT);

// To check connection
LaravelSmpp::enquireLink();

// To set custom configuration
LaravelSmpp::setConfig([...]);
```

#### Full Example (Controller)

```php
<?php

namespace App\Http\Controllers;

use Kstmostofa\LaravelSmpp\Facades\LaravelSmpp;
use Kstmostofa\LaravelSmpp\SMPP;
use Illuminate\Http\Request;

class SmppController extends Controller
{
    public function sendSms(Request $request)
    {
        try {
            LaravelSmpp::getTransport()->open();
            LaravelSmpp::bindTransceiver();

            $messageId = LaravelSmpp::setSender('SENDER', SMPP::TON_ALPHANUMERIC)
                ->setRecipient($request->input('recipient'), SMPP::TON_INTERNATIONAL)
                ->requestDLR() // Request a Delivery Receipt
                ->sendSMS($request->input('message'));

            LaravelSmpp::close();

            return response()->json(['status' => 'success', 'message_id' => $messageId, 'note' => 'DLR will be received asynchronously by a listener process.']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function checkSmppConnection()
    {
        try {
            LaravelSmpp::getTransport()->open();
            LaravelSmpp::bindTransceiver();
            $alive = LaravelSmpp::enquireLink();
            LaravelSmpp::close();

            return response()->json(['status' => 'success', 'connection_alive' => $alive]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
```

### Receiving Delivery Receipts (DLRs)

Delivery Receipts (DLRs) and incoming SMS messages are received asynchronously from the SMPP server. This means that when you send an SMS from your web application (e.g., via a controller), the DLR is not returned as an immediate response to that HTTP request. Instead, the SMPP server sends the DLR to your application at a later time.

To handle incoming DLRs and SMS messages, you need a **separate, long-running process** that maintains a persistent connection to the SMPP server and continuously listens for these messages. This process would typically:

1.  **Bind to the SMPP server** in `transceiver` or `receiver` mode.
2.  **Continuously call `LaravelSmpp::readSMS()`** in a loop to check for incoming PDUs (Protocol Data Units).
3.  **Process the received PDU:** Check if it's a DLR (using `$sms->isDeliveryReceipt()`) or a regular incoming SMS.
4.  **Store the DLR/SMS data:** Typically, this data would be stored in your application's database, allowing your web controllers or other parts of your application to query and display the information as needed.

**Important Considerations:**

*   **Long-Running Processes:** Laravel web controllers are short-lived. For a true DLR listener, you would usually implement this in a custom Artisan command, a queue worker, or a separate daemon process (e.g., using Supervisor or systemd).
*   **`requestDLR()` Method:** When sending an SMS, you must chain the `requestDLR()` method to instruct the SMSC to send a delivery receipt.
*   **Error Handling & Reconnection:** Robust DLR listeners need comprehensive error handling, including graceful disconnections and reconnection logic.
*   **Concurrency:** If you expect a high volume of DLRs, consider how to handle concurrency and avoid blocking operations.

**Example (Conceptual Listener Process - typically an Artisan Command):**

```php
<?php

// This code would typically reside in a Laravel Artisan Command (e.g., app/Console/Commands/SmppListener.php)

use Kstmostofa\LaravelSmpp\Facades\LaravelSmpp;
use Kstmostofa\LaravelSmpp\SMPP;

// Command setup
protected $signature = 'smpp:receive-dlr {--host= : The SMPP host} {--port= : The SMPP port} {--username= : The SMPP username} {--password= : The SMPP password} {--timeout= : The SMPP timeout in milliseconds} {--debug : Enable debug mode}';

protected $description = 'Listens for incoming SMPP Delivery Receipts (DLRs) and Mobile Originated (MO) SMS messages.';


public function handle()
{
    $config = config('smpp'); // Get default config

    // Override with command line options if provided
    if ($this->option('host')) {
        $config['host'] = $this->option('host');
    }
    if ($this->option('port')) {
        $config['port'] = (int) $this->option('port');
    }
    if ($this->option('username')) {
        $config['username'] = $this->option('username');
    }
    if ($this->option('password')) {
        $config['password'] = $this->option('password');
    }
    if ($this->option('timeout')) {
        $config['timeout'] = (int) $this->option('timeout');
    }
    if ($this->option('debug')) {
        $config['debug'] = (bool) $this->option('debug');
    }

    try {
        $smppClient = LaravelSmpp::getFacadeRoot();
        $smppClient->setConfig($config); // Apply the configuration

        // Open transport and bind as transceiver (or receiver if only receiving)
        $smppClient->getTransport()->open();
        $smppClient->bindTransceiver();

        $this->info('Listening for DLRs and incoming messages with config: ' . json_encode($config));

        while (true) {
            try {
                $pdu = $smppClient->readSMS();

                if ($pdu) {
                    if ($pdu instanceof \Kstmostofa\LaravelSmpp\DeliveryReceipt) {
                        $this->info('Received DLR:');
                        $this->info('  Message ID: ' . $pdu->messageId);
                        $this->info('  Delivery Status: ' . $pdu->status);
                        $this->info('  Final Date: ' . ($pdu->finalDate ? $pdu->finalDate->format('Y-m-d H:i:s') : 'N/A'));
                        $this->info('  Error Code: ' . $pdu->errorCode);
                        // TODO: Process DLR, e.g., update database record for the sent message
                    } elseif ($pdu instanceof \Kstmostofa\LaravelSmpp\Sms) {
                        $this->info('Received Mobile Originated (MO) SMS:');
                        $this->info('  From: ' . $pdu->source->value);
                        $this->info('  To: ' . $pdu->destination->value);
                        $this->info('  Message: ' . $pdu->message);
                        // TODO: Process incoming SMS, e.g., store in database, trigger an event
                    }
                }

                // Send enquire_link to keep connection alive (adjust frequency as needed)
                $smppClient->enquireLink();
                usleep(100000); // 100ms delay to prevent busy-waiting
            } catch (\Exception $e) {
                $this->error('Error reading SMS/DLR: ' . $e->getMessage());
                // Attempt to reconnect if the connection is lost
                $this->info('Attempting to reconnect...');
                $smppClient->reconnect();
                $this->info('Reconnected to SMPP.');
            }
        }
    } catch (\Exception $e) {
        $this->error('Failed to bind to SMPP: ' . $e->getMessage());
        return Command::FAILURE;
    } finally {
        if (isset($smppClient) && $smppClient->getTransport()->isOpen()) {
            $smppClient->close();
            $this->info('SMPP listener stopped.');
        }
    }
    return Command::SUCCESS;
    }
}
```

### Running Multiple Listeners with Custom Configurations

With the updated `smpp:receive-dlr` command, you can now run multiple listener processes, each connecting to a different SMPP server or using different credentials. This is particularly useful if you have multiple SMPP accounts or need to connect to different SMSCs simultaneously.

You can pass the configuration details as command-line options:

```bash
php artisan smpp:receive-dlr --host=smpp.example.com --port=2775 --username=user1 --password=pass1 --debug
php artisan smpp:receive-dlr --host=smpp.anotherserver.com --port=2776 --username=user2 --password=pass2
```

For production environments, you would typically use a process manager like [Supervisor](http://supervisord.org/) to manage these long-running processes. Here's a conceptual example of how you might configure Supervisor to run two different SMPP listeners:

```ini
; /etc/supervisor/conf.d/smpp-listeners.conf

[program:smpp-listener-1]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/laravel/artisan smpp:receive-dlr --host=smpp.example.com --port=2775 --username=user1 --password=pass1
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/supervisor/smpp-listener-1.log

[program:smpp-listener-2]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/laravel/artisan smpp:receive-dlr --host=smpp.anotherserver.com --port=2776 --username=user2 --password=pass2
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/supervisor/smpp-listener-2.log
```

Remember to replace `/path/to/your/laravel/artisan` with the actual path to your Artisan executable and adjust `user` and `stdout_logfile` paths as per your server setup.

### Dynamic Listener Management (Database-Driven)

If you have multiple SMPP configurations stored in your database and need to dynamically manage listener processes (e.g., starting new ones, stopping old ones when configurations change), you can implement a dedicated Artisan command to generate and reload Supervisor configurations.

This approach ensures that each SMPP connection runs as an independent, robust process managed by Supervisor.

**1. Database Table for Configurations**
First, ensure you have a database table (e.g., `smpp_connections`) to store your SMPP server details. A basic schema might look like this:

```sql
CREATE TABLE smpp_connections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    host VARCHAR(255) NOT NULL,
    port INT NOT NULL,
    username VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    timeout INT DEFAULT 10000,
    debug BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

**2. Create a Manager Artisan Command**
Create a new Artisan command (e.g., `SmppConfigManager`) that will read your database configurations and generate the Supervisor configuration file.

```bash
php artisan make:command SmppConfigManager
```

**3. Implement `SmppConfigManager` Logic**
Edit `app/Console/Commands/SmppConfigManager.php` with the following logic:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB; // Or your Eloquent model for SMPP configs
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class SmppConfigManager extends Command
{
    protected $signature = 'smpp:manage-listeners';
    protected $description = 'Manages SMPP listener processes based on database configurations.';

    public function handle()
    {
        $this->info('Generating Supervisor configuration for SMPP listeners...');

        // Fetch active SMPP configurations from the database
        // Adjust this query to match your actual database table and model
        $smppConfigs = DB::table('smpp_connections')->where('is_active', true)->get();

        $supervisorConfigContent = "; Dynamically generated SMPP Listener Configuration\n\n";

        foreach ($smppConfigs as $config) {
            $programName = 'smpp-listener-' . $config->id; // Unique name for each program
            $logFileBase = '/var/log/supervisor/' . $programName; // Base for log files

            // Construct the command for the smpp:receive-dlr Artisan command
            // IMPORTANT: Use escapeshellarg() for all user-provided/database values
            $command = sprintf(
                'php %s smpp:receive-dlr --host=%s --port=%d --username=%s --password=%s --timeout=%d %s',
                base_path('artisan'), // Path to your Laravel artisan script
                escapeshellarg($config->host),
                (int)$config->port,
                escapeshellarg($config->username),
                escapeshellarg($config->password),
                (int)$config->timeout,
                $config->debug ? '--debug' : '' // Only add --debug if true
            );

            $supervisorConfigContent .= "[program:$programName]\n";
            $supervisorConfigContent .= "process_name=%(program_name)s_%(process_num)02d\n";
            $supervisorConfigContent .= "command=$command\n";
            $supervisorConfigContent .= "autostart=true\n";
            $supervisorConfigContent .= "autorestart=true\n";
            $supervisorConfigContent .= "user=www-data\n"; // IMPORTANT: Set to the user Supervisor runs as or your app user
            $supervisorConfigContent .= "numprocs=1\n";
            $supervisorConfigContent .= "redirect_stderr=true\n";
            $supervisorConfigContent .= "stdout_logfile=$logFileBase.log\n";
            $supervisorConfigContent .= "stderr_logfile=$logFileBase-error.log\n\n";
        }

        // Write the generated content to a Supervisor config file
        $configFilePath = '/etc/supervisor/conf.d/smpp_dynamic_listeners.conf'; // Adjust path as needed
        try {
            file_put_contents($configFilePath, $supervisorConfigContent);
            $this->info("Supervisor configuration written to: $configFilePath");
        } catch (\Exception $e) {
            $this->error("Failed to write Supervisor config file: " . $e->getMessage());
            return Command::FAILURE;
        }

        // Instruct Supervisor to reload its configuration
        $this->info('Reloading Supervisor configuration...');
        try {
            $process = Process::fromShellCommandline('supervisorctl reread && supervisorctl update');
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $this->info($process->getOutput());
            $this->info('Supervisor configuration reloaded successfully. Listeners updated.');
        } catch (ProcessFailedException $e) {
            $this->error("Failed to reload Supervisor: " . $e->getMessage());
            $this->error($e->getProcess()->getErrorOutput());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
```

**4. Triggering the Manager Command**
You need to run `php artisan smpp:manage-listeners` whenever your SMPP configurations in the database change. This ensures that Supervisor's configuration is up-to-date and that the correct listener processes are running.

Here are the primary ways to trigger this command:

*   **1. Eloquent Model Observers (Recommended for Automation):**
    This is the most Laravel-idiomatic and automated way. If you have an Eloquent model (e.g., `App\Models\SmppConnection`) that represents your SMPP configurations, you can create an Observer for it. This Observer will listen for events on your model (like `created`, `updated`, `deleted`) and automatically call the manager command.

    First, create the Observer:
    ```bash
    php artisan make:observer SmppConnectionObserver --model=SmppConnection
    ```

    Then, implement the logic in `app/Observers/SmppConnectionObserver.php`:
    ```php
    <?php

    namespace App\Observers;

    use App\Models\SmppConnection;
    use Illuminate\Support\Facades\Artisan;

    class SmppConnectionObserver
    {
        public function created(SmppConnection $smppConnection)
        {
            Artisan::call('smpp:manage-listeners');
        }

        public function updated(SmppConnection $smppConnection)
        {
            Artisan::call('smpp:manage-listeners');
        }

        public function deleted(SmppConnection $smppConnection)
        {
            Artisan::call('smpp:manage-listeners');
        }
    }
    ```

    Finally, register the Observer in your `AppServiceProvider` (or a dedicated `ObserverServiceProvider`):
    ```php
    // app/Providers/AppServiceProvider.php

    use App\Models\SmppConnection;
    use App\Observers\SmppConnectionObserver;

    public function boot()
    {
        SmppConnection::observe(SmppConnectionObserver::class);
    }
    ```

*   **2. From an API Endpoint or Controller:**
    If you have an administrative interface (web or API) where users can add, edit, or delete SMPP configurations, you can call the `smpp:manage-listeners` command after a successful database operation.

    ```php
    // app/Http/Controllers/Admin/SmppConfigController.php (Example)

    use App\Http\Controllers\Controller;
    use App\Models\SmppConnection;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Artisan;

    class SmppConfigController extends Controller
    {
        public function store(Request $request)
        {
            // ... validation and saving new SmppConnection to database ...
            $smppConnection = SmppConnection::create($request->all());

            Artisan::call('smpp:manage-listeners'); // Trigger update
            return redirect()->back()->with('success', 'SMPP connection added.');
        }

        public function destroy(SmppConnection $smppConnection)
        {
            $smppConnection->delete();

            Artisan::call('smpp:manage-listeners'); // Trigger update
            return redirect()->back()->with('success', 'SMPP connection deleted.');
        }
    }
    ```

*   **3. Manual Triggering (Artisan Command):**
    For development, testing, or in scenarios where changes are infrequent and not tied to an automated system, you can simply run the command manually from your terminal.

    ```bash
    php artisan smpp:manage-listeners
    ```

*   **4. Deployment Hooks:**
    It's good practice to run the `smpp:manage-listeners` command as part of your deployment process. This ensures that when you deploy new code, your Supervisor configuration for listeners is always in sync with the current state of your database.

    Include `php artisan smpp:manage-listeners` in your deployment script (e.g., Capistrano, Deployer, custom shell script) after database migrations are run.

**Important Considerations:**

*   **Permissions:** The user running `php artisan smpp:manage-listeners` must have write permissions to the Supervisor configuration directory (e.g., `/etc/supervisor/conf.d/`) and execute permissions for `supervisorctl`. This often requires careful setup of `sudoers` or running the command as a privileged user.
*   **Security:** Be extremely cautious with sensitive credentials. Ensure your database connection is secure and that the `smpp:manage-listeners` command is only callable by authorized users/processes. Always use `escapeshellarg()` when constructing shell commands with dynamic data.
*   **Error Handling:** Implement robust error logging and alerting for the `SmppConfigManager` command itself, especially if the Supervisor reload fails.

---

### Custom Configuration (Runtime)


If you need to use different SMPP settings for specific operations without modifying the published configuration file or environment variables, you can use the `setConfig` method on the `SmppClient` instance:

```php
<?php

namespace App\Http\Controllers;

use Kstmostofa\LaravelSmpp\Facades\LaravelSmpp;
use Kstmostofa\LaravelSmpp\Address;
use Kstmostofa\LaravelSmpp\SMPP;
use Illuminate\Http\Request;

class SmppController extends Controller
{
    public function sendSmsWithCustomConfig(Request $request)
    {
        // Override configuration for this specific operation
        LaravelSmpp::setConfig([
            'host' => 'your_custom_host',
            'port' => 2776,
            'username' => 'custom_user',
            'password' => 'custom_pass',
            'timeout' => 5000,
            'debug' => true,
        ]);

        try {
            LaravelSmpp::getTransport()->open();
            LaravelSmpp::bindTransceiver();

            $messageId = LaravelSmpp::setSender('SENDER', SMPP::TON_ALPHANUMERIC)
                ->setRecipient($request->input('recipient'), SMPP::TON_INTERNATIONAL)
                ->requestDLR() // Request a Delivery Receipt
                ->sendSMS($request->input('message'));

            LaravelSmpp::close();

            return response()->json(['status' => 'success', 'message_id' => $messageId]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
```

Original description
=======
PHP-based SMPP client lib
=============

This is a simplified SMPP client lib for sending or receiving smses through [SMPP v3.4](http://www.smsforum.net/SMPP_v3_4_Issue1_2.zip).

In addition to the client, this lib also contains an encoder for converting UTF-8 text to the GSM 03.38 encoding, and a socket wrapper. The socket wrapper provides connection pool, IPv6 and timeout monitoring features on top of PHP's socket extension.

This lib has changed significantly from it's first release, which required namespaces and included some worker components. You'll find that release at [1.0.1-namespaced](https://github.com/onlinecity/php-smpp/tree/1.0.1-namespaced)

This lib requires the [sockets](http://www.php.net/manual/en/book.sockets.php) PHP-extension, and is not supported on Windows. A [windows-compatible](https://github.com/onlinecity/php-smpp/tree/windows-compatible) version is also available.


Connection pools
-----
You can specify a list of connections to have the SocketTransport attempt each one in succession or randomly. Also if you give it a hostname with multiple A/AAAA-records it will try each one.
If you want to monitor the DNS lookups, set defaultDebug to true before constructing the transport.

The (configurable) send timeout governs how long it will wait for each server to timeout. It can take a long time to try a long list of servers, depending on the timeout. You can change the timeout both before and after the connection attempts are made.

The transport supports IPv6 and will prefer IPv6 addresses over IPv4 when available. You can modify this feature by setting forceIpv6 or forceIpv4 to force it to only use IPv6 or IPv4.

In addition to the DNS lookups, it will also look for local IPv4 addresses using gethostbyname(), so "localhost" works for IPv4. For IPv6 localhost specify "::1".


Implementation notes
-----

- You can't connect as a transceiver, otherwise supported by SMPP v.3.4
- The SUBMIT_MULTI operation of SMPP, which sends a SMS to a list of recipients, is not supported atm. You can easily add it though.
- The sockets will return false if the timeout is reached on read() (but not readAll or write).
  You can use this feature to implement an enquire_link policy. If you need to send enquire_link for every 30 seconds of inactivity,
  set a timeout of 30 seconds, and send the enquire_link command after readSMS() returns false.
- The examples above assume that the SMSC default datacoding is [GSM 03.38](http://en.wikipedia.org/wiki/GSM_03.38).
- Remember to activate registered delivery if you want delivery receipts (set to SMPP::REG_DELIVERY_SMSC_BOTH / 0x01).
- Both the SmppSmppClient and transport components support a debug callback, which defaults to [error_log](http://www.php.net/manual/en/function.error-log.php) . Use this to redirect debug information.

F.A.Q.
-----

**Can I use this to send messages from my website?**  
Not on it's own, no. After PHP processes the request on a website, it closes all connections. Most SMPP providers do not want you to open and close connections, you should keep them alive and send enquire_link commands periodically. Which means you probably need to get some kind of long running process, ie. using the [process control functions](http://www.php.net/manual/en/book.pcntl.php), and implement a form of queue system which you can push to from the website. This requires shell level access to the server, and knowledge of unix processes.

**How do I receive delivery receipts or SMS'es?**  
To receive a delivery receipt or a SMS you must connect a receiver in addition to the transmitter. This receiver must wait for a delivery receipt to arrive, which means you probably need to use the [process control functions](http://www.php.net/manual/en/book.pcntl.php).

We do have an open source implementation at [php-smpp-worker](https://github.com/onlinecity/php-smpp-worker) you can look at for inspiration, but we cannot help you with making your own. Perhaps you should look into if your SMSC provider can give you a HTTP based API or using turnkey software such as [kannel](http://www.kannel.org/), this project provides the protocol implementation only and a basic socket wrapper.

**I can't send more than 160 chars**  
There are three built-in methods to send Concatenated SMS (csms); CSMS_16BIT_TAGS, CSMS_PAYLOAD, CSMS_8BIT_UDH. CSMS_16BIT_TAGS is the default, if it don't work try another.

**Is this lib compatible with PHP 5.2.x ?**  
It's tested on PHP 5.3, but is known to work with 5.2 as well.

**Can it run on windows?**  
It requires the sockets extension, which is available on windows, but is incomplete. Use the [windows-compatible](https://github.com/onlinecity/php-smpp/tree/windows-compatible) version instead, which uses fsockopen and stream functions.

**Why am I not seeing any debug output?**  
Remember to implement a debug callback for SocketTransport and SmppSmppClient to use. Otherwise they default to [error_log](http://www.php.net/manual/en/function.error-log.php) which may or may not print to screen.

**Why do I get 'res_nsend() failed' or 'Could not connect to any of the specified hosts' errors?**  
Your provider's DNS server probably has an issue with IPv6 addresses (AAAA records). Try to set ```SocketTransport::$forceIpv4=true;```. You can also try specifying an IP-address (or a list of IPs) instead. Setting ```SocketTransport:$defaultDebug=true;``` before constructing the transport is also useful in resolving connection issues.

**I tried forcing IPv4 and/or specifying an IP-address, but I'm still getting 'Could not connect to any of the specified hosts'?**  
It would be a firewall issue that's preventing your connection, or something else entirely. Make sure debug output is enabled and displayed. If you see something like 'Socket connect to 1.2.3.4:2775 failed; Operation timed out' this means a connection could not be etablished. If this isn't a firewall issue, you might try increasing the connect timeout. The sendTimeout also specifies the connect timeout, call ```$transport->setSendTimeout(10000);``` to set a 10-second timeout.

**Why do I get 'Failed to read reply to command: 0x4', 'Message Length is invalid' or 'Error in optional part' errors?**  
Most likely your SMPP provider doesn't support NULL-terminating the message field. The specs aren't clear on this issue, so there is a toggle. Set ```SmppSmppClient::$sms_null_terminate_octetstrings = false;``` and try again.

**What does 'Bind Failed' mean?**  
It typically means your SMPP provider rejected your login credentials, ie. your username or password.

**Can I test the client library without a SMPP server?**  
Many service providers can give you a demo account, but you can also use the [logica opensmpp simulator](http://opensmpp.logica.com/CommonPart/Introduction/Introduction.htm#simulator) (java) or [smsforum client test tool](http://www.smsforum.net/sctt_v1.0.Linux.tar.gz) (linux binary). In addition to a number of real-life SMPP servers this library is tested against these simulators.

**I have an issue that not mentioned here, what do I do?**  
Please obtain full debug information, and open an issue here on github. Make sure not to include the Send PDU hex-codes of the BindTransmitter call, since it will contain your username and password. Other hex-output is fine, and greatly appeciated. Any PHP Warnings or Notices could also be important. Please include information about what SMPP server you are connecting to, and any specifics.
```

### Custom Configuration (Runtime)


If you need to use different SMPP settings for specific operations without modifying the published configuration file or environment variables, you can use the `setConfig` method on the `SmppClient` instance:

```php
<?php

namespace App\Http\Controllers;

use Kstmostofa\LaravelSmpp\Facades\LaravelSmpp;
use Kstmostofa\LaravelSmpp\Address;
use Kstmostofa\LaravelSmpp\SMPP;
use Illuminate\Http\Request;

class SmppController extends Controller
{
    public function sendSmsWithCustomConfig(Request $request)
    {
        // Override configuration for this specific operation
        LaravelSmpp::setConfig([
            'host' => 'your_custom_host',
            'port' => 2776,
            'username' => 'custom_user',
            'password' => 'custom_pass',
            'timeout' => 5000,
            'debug' => true,
        ]);

        try {
            LaravelSmpp::getTransport()->open();
            LaravelSmpp::bindTransceiver();

            $from = new Address('CUSTOM_SENDER', SMPP::TON_ALPHANUMERIC);
            $to = new Address($request->input('recipient'), SMPP::TON_INTERNATIONAL);

            $messageId = LaravelSmpp::sendSMS(
                $from,
                $to,
                $request->input('message'),
                null,
                SMPP::DATA_CODING_DEFAULT
            );

            LaravelSmpp::close();

            return response()->json(['status' => 'success', 'message_id' => $messageId]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
```

### Custom Configuration (Runtime)


If you need to use different SMPP settings for specific operations without modifying the published configuration file or environment variables, you can use the `setConfig` method on the `SmppClient` instance:

```php
<?php

namespace App\Http\Controllers;

use Kstmostofa\LaravelSmpp\Facades\LaravelSmpp;
use Kstmostofa\LaravelSmpp\Address;
use Kstmostofa\LaravelSmpp\SMPP;
use Illuminate\Http\Request;

class SmppController extends Controller
{
    public function sendSmsWithCustomConfig(Request $request)
    {
        // Override configuration for this specific operation
        LaravelSmpp::setConfig([
            'host' => 'your_custom_host',
            'port' => 2776,
            'username' => 'custom_user',
            'password' => 'custom_pass',
            'timeout' => 5000,
            'debug' => true,
        ]);

        try {
            LaravelSmpp::getTransport()->open();
            LaravelSmpp::bindTransceiver();

            $messageId = LaravelSmpp::setSender('SENDER', SMPP::TON_ALPHANUMERIC)
                ->setRecipient($request->input('recipient'), SMPP::TON_INTERNATIONAL)
                ->requestDLR() // Request a Delivery Receipt
                ->sendSMS($request->input('message'));

            LaravelSmpp::close();

            return response()->json(['status' => 'success', 'message_id' => $messageId]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
```

Original description
=======
PHP-based SMPP client lib
=============

This is a simplified SMPP client lib for sending or receiving smses through [SMPP v3.4](http://www.smsforum.net/SMPP_v3_4_Issue1_2.zip).

In addition to the client, this lib also contains an encoder for converting UTF-8 text to the GSM 03.38 encoding, and a socket wrapper. The socket wrapper provides connection pool, IPv6 and timeout monitoring features on top of PHP's socket extension.

This lib has changed significantly from it's first release, which required namespaces and included some worker components. You'll find that release at [1.0.1-namespaced](https://github.com/onlinecity/php-smpp/tree/1.0.1-namespaced)

This lib requires the [sockets](http://www.php.net/manual/en/book.sockets.php) PHP-extension, and is not supported on Windows. A [windows-compatible](https://github.com/onlinecity/php-smpp/tree/windows-compatible) version is also available.


Connection pools
-----
You can specify a list of connections to have the SocketTransport attempt each one in succession or randomly. Also if you give it a hostname with multiple A/AAAA-records it will try each one.
If you want to monitor the DNS lookups, set defaultDebug to true before constructing the transport.

The (configurable) send timeout governs how long it will wait for each server to timeout. It can take a long time to try a long list of servers, depending on the timeout. You can change the timeout both before and after the connection attempts are made.

The transport supports IPv6 and will prefer IPv6 addresses over IPv4 when available. You can modify this feature by setting forceIpv6 or forceIpv4 to force it to only use IPv6 or IPv4.

In addition to the DNS lookups, it will also look for local IPv4 addresses using gethostbyname(), so "localhost" works for IPv4. For IPv6 localhost specify "::1".


Implementation notes
-----

- You can't connect as a transceiver, otherwise supported by SMPP v.3.4
- The SUBMIT_MULTI operation of SMPP, which sends a SMS to a list of recipients, is not supported atm. You can easily add it though.
- The sockets will return false if the timeout is reached on read() (but not readAll or write).
  You can use this feature to implement an enquire_link policy. If you need to send enquire_link for every 30 seconds of inactivity,
  set a timeout of 30 seconds, and send the enquire_link command after readSMS() returns false.
- The examples above assume that the SMSC default datacoding is [GSM 03.38](http://en.wikipedia.org/wiki/GSM_03.38).
- Remember to activate registered delivery if you want delivery receipts (set to SMPP::REG_DELIVERY_SMSC_BOTH / 0x01).
- Both the SmppSmppClient and transport components support a debug callback, which defaults to [error_log](http://www.php.net/manual/en/function.error-log.php) . Use this to redirect debug information.

F.A.Q.
-----

**Can I use this to send messages from my website?**  
Not on it's own, no. After PHP processes the request on a website, it closes all connections. Most SMPP providers do not want you to open and close connections, you should keep them alive and send enquire_link commands periodically. Which means you probably need to get some kind of long running process, ie. using the [process control functions](http://www.php.net/manual/en/book.pcntl.php), and implement a form of queue system which you can push to from the website. This requires shell level access to the server, and knowledge of unix processes.

**How do I receive delivery receipts or SMS'es?**  
To receive a delivery receipt or a SMS you must connect a receiver in addition to the transmitter. This receiver must wait for a delivery receipt to arrive, which means you probably need to use the [process control functions](http://www.php.net/manual/en/book.pcntl.php).

We do have an open source implementation at [php-smpp-worker](https://github.com/onlinecity/php-smpp-worker) you can look at for inspiration, but we cannot help you with making your own. Perhaps you should look into if your SMSC provider can give you a HTTP based API or using turnkey software such as [kannel](http://www.kannel.org/), this project provides the protocol implementation only and a basic socket wrapper.

**I can't send more than 160 chars**  
There are three built-in methods to send Concatenated SMS (csms); CSMS_16BIT_TAGS, CSMS_PAYLOAD, CSMS_8BIT_UDH. CSMS_16BIT_TAGS is the default, if it don't work try another.

**Is this lib compatible with PHP 5.2.x ?**  
It's tested on PHP 5.3, but is known to work with 5.2 as well.

**Can it run on windows?**  
It requires the sockets extension, which is available on windows, but is incomplete. Use the [windows-compatible](https://github.com/onlinecity/php-smpp/tree/windows-compatible) version instead, which uses fsockopen and stream functions.

**Why am I not seeing any debug output?**  
Remember to implement a debug callback for SocketTransport and SmppSmppClient to use. Otherwise they default to [error_log](http://www.php.net/manual/en/function.error-log.php) which may or may not print to screen.

**Why do I get 'res_nsend() failed' or 'Could not connect to any of the specified hosts' errors?**  
Your provider's DNS server probably has an issue with IPv6 addresses (AAAA records). Try to set ```SocketTransport::$forceIpv4=true;```. You can also try specifying an IP-address (or a list of IPs) instead. Setting ```SocketTransport:$defaultDebug=true;``` before constructing the transport is also useful in resolving connection issues.

**I tried forcing IPv4 and/or specifying an IP-address, but I'm still getting 'Could not connect to any of the specified hosts'?**  
It would be a firewall issue that's preventing your connection, or something else entirely. Make sure debug output is enabled and displayed. If you see something like 'Socket connect to 1.2.3.4:2775 failed; Operation timed out' this means a connection could not be etablished. If this isn't a firewall issue, you might try increasing the connect timeout. The sendTimeout also specifies the connect timeout, call ```$transport->setSendTimeout(10000);``` to set a 10-second timeout.

**Why do I get 'Failed to read reply to command: 0x4', 'Message Length is invalid' or 'Error in optional part' errors?**  
Most likely your SMPP provider doesn't support NULL-terminating the message field. The specs aren't clear on this issue, so there is a toggle. Set ```SmppSmppClient::$sms_null_terminate_octetstrings = false;``` and try again.

**What does 'Bind Failed' mean?**  
It typically means your SMPP provider rejected your login credentials, ie. your username or password.

**Can I test the client library without a SMPP server?**  
Many service providers can give you a demo account, but you can also use the [logica opensmpp simulator](http://opensmpp.logica.com/CommonPart/Introduction/Introduction.htm#simulator) (java) or [smsforum client test tool](http://www.smsforum.net/sctt_v1.0.Linux.tar.gz) (linux binary). In addition to a number of real-life SMPP servers this library is tested against these simulators.

**I have an issue that not mentioned here, what do I do?**  
Please obtain full debug information, and open an issue here on github. Make sure not to include the Send PDU hex-codes of the BindTransmitter call, since it will contain your username and password. Other hex-output is fine, and greatly appeciated. Any PHP Warnings or Notices could also be important. Please include information about what SMPP server you are connecting to, and any specifics.
