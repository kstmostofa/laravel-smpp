<?php


namespace Kstmostofa\LaravelSmpp\Transport;

use Kstmostofa\LaravelSmpp\Exceptions\SocketTransportException;

!defined('MSG_DONTWAIT') && define('MSG_DONTWAIT', 64);

/**
 * TCP Socket Transport for use with multiple protocols.
 * Supports connection pools and IPv6 in addition to providing a few public methods to make life easier.
 * It's primary purpose is long-running connections, since it don't support socket re-use, ip-blacklisting, etc.
 * It assumes a blocking/synchronous architecture, and will block when reading or writing, but will enforce timeouts.
 *
 * Copyright (C) 2011 OnlineCity
 * Licensed under the MIT license, which can be read at: http://www.opensource.org/licenses/mit-license.php
 * @author mostofa122@gmail.com
 */
class Socket
{
    protected $socket;
    protected $hosts;
    protected $persist;
    protected $debugHandler;
    public $debug;

    protected static $defaultSendTimeout = 100;
    protected static $defaultRecvTimeout = 750;
    public static $defaultDebug = false;
    // New: default connect timeout (ms)
    protected static $defaultConnectTimeout = 5000;

    public static $forceIpv6 = false;
    public static $forceIpv4 = false;
    public static $randomHost = false;

    /**
     * Construct a new socket for this transport to use.
     *
     * @param array $hosts list of hosts to try.
     * @param mixed $ports list of ports to try, or a single common port
     * @param boolean $persist use persistent sockets
     * @param mixed $debugHandler callback for debug info
     */
    public function __construct(array $hosts, $ports, $persist = false, $debugHandler = null)
    {
        $this->debug = self::$defaultDebug;
        $this->debugHandler = $debugHandler ? $debugHandler : 'error_log';

        // Deal with optional port
        $h = [];
        foreach ($hosts as $key => $host) {
            $h[] = [$host, is_array($ports) ? $ports[$key] : $ports];
        }
        if (self::$randomHost) {
            shuffle($h);
        }
        $this->resolveHosts($h);

        $this->persist = $persist;
    }

    /**
     * Resolve the hostnames into IPs, and sort them into IPv4 or IPv6 groups.
     * If using DNS hostnames, and all lookups fail, a InvalidArgumentException is thrown.
     *
     * @param array $hosts
     * @throws \InvalidArgumentException
     */
    protected function resolveHosts($hosts)
    {
        $i = 0;
        foreach ($hosts as $host) {
            list($hostname, $port) = $host;
            $ip4s = [];
            $ip6s = [];
            if (preg_match('/^([12]?[0-9]?[0-9]\.){3}([12]?[0-9]?[0-9])$/', $hostname)) {
                // IPv4 address
                $ip4s[] = $hostname;
            } else if (preg_match('/^([0-9a-f:]+):[0-9a-f]{1,4}$/i', $hostname)) {
                // IPv6 address
                $ip6s[] = $hostname;
            } else { // Do a DNS lookup
                if (!self::$forceIpv4) {
                    // if not in IPv4 only mode, check the AAAA records first
                    $records = dns_get_record($hostname, DNS_AAAA);
                    if ($records === false && $this->debug) {
                        call_user_func($this->debugHandler, 'DNS lookup for AAAA records for: ' . $hostname . ' failed');
                    }
                    if ($records) {
                        foreach ($records as $r) {
                            if (isset($r['ipv6']) && $r['ipv6']) {
                                $ip6s[] = $r['ipv6'];
                            }
                        }
                    }
                    if ($this->debug) {
                        call_user_func($this->debugHandler, "IPv6 addresses for $hostname: " . implode(', ', $ip6s));
                    }
                }
                if (!self::$forceIpv6) {
                    // if not in IPv6 mode check the A records also
                    $records = dns_get_record($hostname, DNS_A);
                    if ($records === false && $this->debug) {
                        call_user_func($this->debugHandler, 'DNS lookup for A records for: ' . $hostname . ' failed');
                    }
                    if ($records) {
                        foreach ($records as $r) {
                            if (isset($r['ip']) && $r['ip']) $ip4s[] = $r['ip'];
                        }
                    }
                    // also try gethostbyname, since name could also be something else, such as "localhost" etc.
                    $ip = gethostbyname($hostname);
                    if ($ip != $hostname && !in_array($ip, $ip4s)) {
                        $ip4s[] = $ip;
                    }
                    if ($this->debug) {
                        call_user_func($this->debugHandler, "IPv4 addresses for $hostname: " . implode(', ', $ip4s));
                    }
                }
            }

            // Did we get any results?
            if (
                (self::$forceIpv4 && empty($ip4s))
                ||
                (self::$forceIpv6 && empty($ip6s))
                ||
                (empty($ip4s) && empty($ip6s))
            ) {
                continue;
            }

            if ($this->debug) {
                $i += count($ip4s) + count($ip6s);
            }

            // Add results to pool
            $this->hosts[] = [$hostname, $port, $ip6s, $ip4s];
        }
        if ($this->debug) {
            call_user_func(
                $this->debugHandler,
                "Built connection pool of " . count($this->hosts) . " host(s) with " . $i . " ip(s) in total"
            );
        }
        if (empty($this->hosts)) {
            throw new \InvalidArgumentException('No valid hosts was found');
        }
    }

    /**
     * Get a reference to the socket.
     * You should use the public functions rather than the socket directly
     */
    public function getSocket()
    {
        return $this->socket;
    }

    /**
     * Get an arbitrary option
     *
     * @param integer $option
     * @param integer $lvl
     *
     * @return array|false|int
     */
    public function getSocketOption($option, $lvl = SOL_SOCKET)
    {
        return socket_get_option($this->socket, $lvl, $option);
    }

    /**
     * Set an arbitrary option
     *
     * @param integer $option
     * @param mixed $value
     * @param integer $lvl
     *
     * @return bool
     */
    public function setSocketOption($option, $value, $lvl = SOL_SOCKET)
    {
        return socket_set_option($this->socket, $lvl, $option, $value);
    }

    /**
     * Sets the send timeout.
     * Returns true on success, or false.
     * @param int $timeout Timeout in milliseconds.
     * @return boolean
     */
    public function setSendTimeout($timeout)
    {
        if (!$this->isOpen()) {
            self::$defaultSendTimeout = $timeout;
        } else {
            return socket_set_option(
                $this->socket,
                SOL_SOCKET,
                SO_SNDTIMEO,
                $this->millisecToSolArray($timeout)
            );
        }
    }

    /**
     * Sets the receive timeout.
     * Returns true on success, or false.
     * @param int $timeout Timeout in milliseconds.
     * @return boolean
     */
    public function setRecvTimeout($timeout)
    {
        if (!$this->isOpen()) {
            self::$defaultRecvTimeout = $timeout;
        } else {
            return socket_set_option(
                $this->socket,
                SOL_SOCKET,
                SO_RCVTIMEO,
                $this->millisecToSolArray($timeout)
            );
        }
    }

    /** Set default connect timeout (ms) before open() */
    public static function setDefaultConnectTimeout($ms)
    {
        self::$defaultConnectTimeout = (int)$ms;
    }

    /**
     * Check if the socket is constructed, and there are no exceptions on it
     * Returns false if it's closed.
     * Throws SocketTransportException is state could not be ascertained
     * @throws SocketTransportException
     */
    public function isOpen()
    {
        if (!(is_resource($this->socket) || $this->socket instanceof \Socket)) {
            return false;
        }

        $r = null;
        $w = null;
        $e = [$this->socket];
        $res = socket_select($r, $w, $e, 0);

        if ($res === false) {
            throw new SocketTransportException(
                'Could not examine socket; ' . socket_strerror(socket_last_error()),
                socket_last_error()
            );
        }

        // if there is an exception on our socket it's probably dead
        if (!empty($e)) {
            return false;
        }

        return true;
    }

    /**
     * Convert a milliseconds into a socket sec+usec array
     * @param integer $milliseconds
     * @return array
     */
    private function millisecToSolArray($milliseconds)
    {
        $usec = $milliseconds * 1000;
        return ['sec' => (int)floor($usec / 1000000), 'usec' => $usec % 1000000];
    }

    /**
     * Internal helper: attempt a non-blocking connect with timeout handling.
     * Returns true on success; on failure updates $lastErrorCode/$lastErrorMsg.
     */
    private function attemptConnect($family, $ip, $port, &$lastErrorCode, &$lastErrorMsg)
    {
        $sock = @socket_create($family, SOCK_STREAM, SOL_TCP);
        if ($sock === false) {
            $lastErrorCode = socket_last_error();
            $lastErrorMsg = socket_strerror($lastErrorCode);
            if ($this->debug) call_user_func($this->debugHandler, "Could not create socket ($family); $lastErrorMsg");
            return false;
        }
        // Apply I/O timeouts
        @socket_set_option($sock, SOL_SOCKET, SO_SNDTIMEO, $this->millisecToSolArray(self::$defaultSendTimeout));
        @socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, $this->millisecToSolArray(self::$defaultRecvTimeout));

        // Make non-blocking for implementing our own connect timeout
        @socket_set_nonblock($sock);
        $r = @socket_connect($sock, $ip, $port);
        if ($r) { // immediate success
            @socket_set_block($sock);
            $this->socket = $sock;
            return true;
        }
        $err = socket_last_error($sock);
        // Transient / in-progress errors we can wait on
        $transient = [
            defined('SOCKET_EINPROGRESS') ? SOCKET_EINPROGRESS : 115,
            defined('SOCKET_EALREADY') ? SOCKET_EALREADY : 114,
            defined('SOCKET_EWOULDBLOCK') ? SOCKET_EWOULDBLOCK : 11,
        ];
        if (in_array($err, $transient, true)) {
            $sec = (int)floor(self::$defaultConnectTimeout / 1000);
            $usec = (self::$defaultConnectTimeout % 1000) * 1000;
            $rArr = null;
            $wArr = [$sock];
            $eArr = [$sock];
            $sel = @socket_select($rArr, $wArr, $eArr, $sec, $usec);
            if ($sel === false) {
                $lastErrorCode = socket_last_error($sock);
                $lastErrorMsg = socket_strerror($lastErrorCode);
            } elseif ($sel === 0) {
                $lastErrorMsg = 'Connection timed out';
                // Use generic ETIMEDOUT if defined else fallback 110
                $lastErrorCode = defined('SOCKET_ETIMEDOUT') ? SOCKET_ETIMEDOUT : 110;
            } else {
                // Writable => check SO_ERROR
                $soErr = @socket_get_option($sock, SOL_SOCKET, SO_ERROR);
                if ($soErr === 0 || $soErr === '0') {
                    @socket_set_block($sock);
                    $this->socket = $sock;
                    return true;
                }
                $lastErrorCode = $soErr ?: $err;
                $lastErrorMsg = socket_strerror($lastErrorCode);
            }
        } else {
            $lastErrorCode = $err;
            $lastErrorMsg = socket_strerror($err);
        }
        if ($this->debug) call_user_func($this->debugHandler, "Connect to $ip:$port failed; $lastErrorMsg ($lastErrorCode)");
        @socket_close($sock);
        return false;
    }

    /**
     * Open the socket, trying to connect to each host in succession.
     * This will prefer IPv6 connections if forceIpv4 is not enabled.
     * If all hosts fail, a SocketTransportException is thrown.
     *
     * NOTE: Previous implementation reused the same socket resource for multiple
     * connect attempts. After a failed connect some OS stacks leave the socket
     * in a bad state causing all subsequent attempts to fail even if the next
     * IP is reachable. We now create a fresh socket per IP attempt.
     *
     * @throws SocketTransportException
     */
    public function open()
    {
        $lastErrorCode = null;
        $lastErrorMsg = null;

        $it = new \ArrayIterator($this->hosts);
        while ($it->valid()) {
            list($hostname, $port, $ip6s, $ip4s) = $it->current();

            // IPv6 first unless forced otherwise
            if (!self::$forceIpv4 && !empty($ip6s)) {
                foreach ($ip6s as $ip) {
                    if ($this->debug) call_user_func($this->debugHandler, "Connecting to $ip:$port (IPv6)...");
                    if ($this->attemptConnect(AF_INET6, $ip, $port, $lastErrorCode, $lastErrorMsg)) {
                        if ($this->debug) call_user_func($this->debugHandler, "Connected to $ip:$port (IPv6)!");
                        return;
                    }
                }
            }

            if (!self::$forceIpv6 && !empty($ip4s)) {
                foreach ($ip4s as $ip) {
                    if ($this->debug) call_user_func($this->debugHandler, "Connecting to $ip:$port (IPv4)...");
                    if ($this->attemptConnect(AF_INET, $ip, $port, $lastErrorCode, $lastErrorMsg)) {
                        if ($this->debug) call_user_func($this->debugHandler, "Connected to $ip:$port (IPv4)!");
                        return;
                    }
                }
            }
            $it->next();
        }

        // If we reach here everything failed
        if ($lastErrorCode !== null) {
            throw new SocketTransportException(
                'Could not connect to any of the specified hosts; last error: ' . $lastErrorMsg,
                $lastErrorCode
            );
        }
        throw new SocketTransportException('Could not connect to any of the specified hosts');
    }

    /**
     * Do a clean shutdown of the socket.
     * Since we don't reuse sockets, we can just close and forget about it,
     * but we choose to wait (linger) for the last data to come through.
     */
    public function close()
    {
        $arrOpt = ['l_onoff' => 1, 'l_linger' => 1];
        socket_set_block($this->socket);
        socket_set_option($this->socket, SOL_SOCKET, SO_LINGER, $arrOpt);
        socket_close($this->socket);
    }

    /**
     * Check if there is data waiting for us on the wire
     * @return boolean
     * @throws SocketTransportException
     */
    public function hasData()
    {
        $r = [$this->socket];
        $w = null;
        $e = null;
        $res = socket_select($r, $w, $e, 0);
        if ($res === false) {
            throw new SocketTransportException(
                'Could not examine socket; ' . socket_strerror(socket_last_error()),
                socket_last_error()
            );
        }

        if (!empty($r)) {
            return true;
        }

        return false;
    }

    /**
     * Read up to $length bytes from the socket.
     * Does not guarantee that all the bytes are read.
     * Returns false on EOF
     * Returns false on timeout (technically EAGAIN error).
     * Throws SocketTransportException if data could not be read.
     *
     * @param integer $length
     * @return mixed
     * @throws SocketTransportException
     */
    public function read($length)
    {
        $d = socket_read($this->socket, $length, PHP_BINARY_READ);
        // sockets give EAGAIN on timeout
        if ($d === false && socket_last_error() === SOCKET_EAGAIN) {
            return false;
        }
        if ($d === false) {
            throw new SocketTransportException(
                'Could not read ' . $length . ' bytes from socket; ' . socket_strerror(socket_last_error()),
                socket_last_error()
            );
        }
        if ($d === '') {
            return false;
        }

        return $d;
    }

    /**
     * Read all the bytes, and block until they are read.
     * Timeout throws SocketTransportException
     *
     * @param integer $length
     * @return string
     */
    public function readAll($length)
    {
        $d = "";
        $r = 0;
        $readTimeout = socket_get_option($this->socket, SOL_SOCKET, SO_RCVTIMEO);
        while ($r < $length) {
            $buf = '';
            $r += socket_recv($this->socket, $buf, $length - $r, MSG_DONTWAIT);
            if ($r === false) {
                throw new SocketTransportException(
                    'Could not read ' . $length . ' bytes from socket; ' . socket_strerror(socket_last_error()),
                    socket_last_error()
                );
            }
            $d .= $buf;
            if ($r == $length) {
                return $d;
            }

            // wait for data to be available, up to timeout
            $r = [$this->socket];
            $w = null;
            $e = [$this->socket];
            $res = socket_select($r, $w, $e, $readTimeout['sec'], $readTimeout['usec']);

            // check
            if ($res === false) {
                throw new SocketTransportException(
                    'Could not examine socket; ' . socket_strerror(socket_last_error()),
                    socket_last_error()
                );
            }
            if (!empty($e)) {
                throw new SocketTransportException(
                    'Socket exception while waiting for data; ' . socket_strerror(socket_last_error()),
                    socket_last_error()
                );
            }
            if (empty($r)) {
                throw new SocketTransportException('Timed out waiting for data on socket');
            }
        }
    }

    /**
     * Write (all) data to the socket.
     * Timeout throws SocketTransportException
     *
     * @param $buffer
     * @param integer $length
     */
    public function write($buffer, $length)
    {
        $r = $length;
        $writeTimeout = socket_get_option($this->socket, SOL_SOCKET, SO_SNDTIMEO);

        while ($r > 0) {
            $wrote = socket_write($this->socket, $buffer, $r);
            if ($wrote === false) {
                throw new SocketTransportException(
                    'Could not write ' . $length . ' bytes to socket; ' . socket_strerror(socket_last_error()),
                    socket_last_error()
                );
            }
            $r -= $wrote;
            if ($r == 0) {
                return;
            }

            $buffer = substr($buffer, $wrote);

            // wait for the socket to accept more data, up to timeout
            $r = null;
            $w = [$this->socket];
            $e = [$this->socket];
            $res = socket_select($r, $w, $e, $writeTimeout['sec'], $writeTimeout['usec']);

            // check
            if ($res === false) {
                throw new SocketTransportException(
                    'Could not examine socket; ' . socket_strerror(socket_last_error()),
                    socket_last_error()
                );
            }
            if (!empty($e)) {
                throw new SocketTransportException(
                    'Socket exception while waiting to write data; ' . socket_strerror(socket_last_error()),
                    socket_last_error()
                );
            }
            if (empty($w)) {
                throw new SocketTransportException('Timed out waiting to write data on socket');
            }
        }
    }
}
