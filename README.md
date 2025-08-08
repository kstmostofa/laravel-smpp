# Laravel SMPP

A modern Laravel-friendly wrapper around an SMPP v3.4 client for sending and receiving SMS, with delivery receipt (DLR) support, connection management, and extensible listener patterns.

## Features

- Send single or concatenated (long) SMS (GSM 03.38 / UCS-2)
- Request and parse Delivery Receipts (DLRs)
- Receive MO (mobile originated) messages
- Transmitter / Receiver / Transceiver bind modes
- Auto DNS resolution, IPv4 / IPv6 (with force flags)
- Custom timeouts & non-blocking connect with fallback
- Clean Laravel Facade API
- You create your own long‑running DLR listener (example provided)

## Installation

```bash
composer require kstmostofa/laravel-smpp
```

Auto-discovery registers the service provider and facade.

## Configuration

Publish config:

```bash
php artisan vendor:publish --provider="Kstmostofa\\LaravelSmpp\\LaravelSmppServiceProvider" --tag="smpp-config"
```

`config/smpp.php`:

```php
return [
  'host' => env('SMPP_HOST','127.0.0.1'),
  'port' => env('SMPP_PORT',2775),
  'username' => env('SMPP_USERNAME','smppuser'),
  'password' => env('SMPP_PASSWORD','smpppass'),
  'timeout' => env('SMPP_TIMEOUT',10000),
  'debug' => env('SMPP_DEBUG',false),
];
```

## Quick Send Example

```php
use Kstmostofa\LaravelSmpp\Facades\LaravelSmpp;
use Kstmostofa\LaravelSmpp\SMPP;

LaravelSmpp::getTransport()->open();
LaravelSmpp::bindTransceiver();
$id = LaravelSmpp::setSender('SENDER', SMPP::TON_ALPHANUMERIC)
    ->setRecipient('1234567890', SMPP::TON_INTERNATIONAL)
    ->requestDLR()
    ->sendSMS('Hello world');
LaravelSmpp::close();
```

## Creating Your Own DLR / MO Listener

DLRs and MO messages are asynchronous. Implement a custom Artisan command that keeps a persistent bind and loops reading PDUs.

### 1. Make a Command

```bash
php artisan make:command SmppReceive --command=smpp:receive
```

### 2. Implement Logic (`app/Console/Commands/SmppReceive.php`)

```php
<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kstmostofa\LaravelSmpp\Facades\LaravelSmpp;
use Kstmostofa\LaravelSmpp\DeliveryReceipt;
use Kstmostofa\LaravelSmpp\Sms;
use Kstmostofa\LaravelSmpp\Transport\Socket;

class SmppReceive extends Command
{
    protected $signature = 'smpp:receive {--host=} {--port=} {--username=} {--password=} {--timeout=} {--debug}';
    protected $description = 'Listen for SMPP Delivery Receipts (DLRs) and MO SMS messages';

    public function handle()
    {
        $cfg = config('smpp');
        foreach(['host','port','username','password','timeout'] as $k){ if($this->option($k)!==null) $cfg[$k]=$this->option($k); }
        if($this->option('debug')) $cfg['debug']=true;

        // Optional: force IPv4 in problematic networks
        // Socket::$forceIpv4 = true;

        try {
            LaravelSmpp::setConfig($cfg);
            LaravelSmpp::getTransport()->open();
            LaravelSmpp::bindTransceiver();
            $this->info('Connected & bound: '.json_encode($cfg));
        } catch(\Throwable $e){
            $this->error('Initial connect failed: '.$e->getMessage());
            return 1;
        }

        while(true){
            try {
                $pdu = LaravelSmpp::readSMS();
                if($pdu instanceof DeliveryReceipt){
                    // Persist/update message status in DB
                    $this->info('[DLR] id='.$pdu->messageId.' status='.$pdu->status);
                } elseif($pdu instanceof Sms){
                    // Store inbound MO
                    $this->info('[MO ] from='.$pdu->source->value.' text='.$pdu->message);
                }
                // Keep link alive (adjust cadence as needed)
                LaravelSmpp::enquireLink();
                usleep(100000); // 100ms
            } catch(\Throwable $e){
                $this->error('Loop error: '.$e->getMessage());
                sleep(1);
                try { LaravelSmpp::reconnect(); } catch(\Throwable $re){ $this->error('Reconnect failed: '.$re->getMessage()); }
            }
        }
    }
}
```

### 3. Run Listener

```bash
php artisan smpp:receive --host=smpp.example.com --username=user --password=pass --debug
```

Manage via Supervisor/systemd for production.

## Runtime Config Override

```php
LaravelSmpp::setConfig([
  'host'=>'alt.host','port'=>2776,'username'=>'alt','password'=>'secret','timeout'=>15000,'debug'=>true,
]);
```

## Forcing IPv4 / IPv6

```php
use Kstmostofa\LaravelSmpp\Transport\Socket;
Socket::$forceIpv4 = true; // or Socket::$forceIpv6 = true;
```

## Debugging

Set `SMPP_DEBUG=true` or pass `--debug`. Debug output uses `error_log`.

## Troubleshooting

| Issue                                         | Tip                                                            |
| --------------------------------------------- | -------------------------------------------------------------- |
| Could not connect / Operation now in progress | Check firewall, force IPv4, correct host/port                  |
| Bind Failed                                   | Verify credentials, system type permissions                    |
| No DLR                                        | Ensure `requestDLR()` chained & listener running               |
| Truncated >160 chars                          | Library segments automatically if GSM/UCS2; verify data_coding |
| Stuck/slow                                    | Increase timeout, verify network latency                       |

## Contributing

PRs welcome (tests + README update).

## License

MIT
