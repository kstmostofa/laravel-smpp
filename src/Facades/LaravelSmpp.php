<?php

namespace Kstmostofa\LaravelSmpp\Facades;

use Illuminate\Support\Facades\Facade;
use Kstmostofa\LaravelSmpp\Transport\Socket;

class LaravelSmpp extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'smpp';
    }

    /**
     * Get the underlying SmppClient instance.
     *
     * @return \Kstmostofa\LaravelSmpp\SmppClient
     */
    protected static function getSmppClient()
    {
        return static::$app['smpp'];
    }

    /**
     * Get the transport instance.
     *
     * @return Socket
     */
    public static function getTransport()
    {
        return static::getSmppClient()->transport;
    }

    /**
     * Binds the receiver.
     *
     * @return bool
     * @throws \Kstmostofa\LaravelSmpp\Exceptions\SmppException
     * @throws \Exception
     */
    public static function bindReceiver()
    {
        return static::getSmppClient()->bindReceiver();
    }

    /**
     * Binds the transmitter.
     *
     * @return bool
     * @throws \Kstmostofa\LaravelSmpp\Exceptions\SmppException
     * @throws \Exception
     */
    public static function bindTransmitter()
    {
        return static::getSmppClient()->bindTransmitter();
    }

    /**
     * Binds the transceiver.
     *
     * @return bool
     * @throws \Kstmostofa\LaravelSmpp\Exceptions\SmppException
     * @throws \Exception
     */
    public static function bindTransceiver()
    {
        return static::getSmppClient()->bindTransceiver();
    }

    /**
     * Closes the session on the SMSC server.
     */
    public static function close()
    {
        return static::getSmppClient()->close();
    }

    /**
     * Send the enquire link command.
     * @return \Kstmostofa\LaravelSmpp\Pdu
     * @throws \Exception
     */
    public static function enquireLink()
    {
        return static::getSmppClient()->enquireLink();
    }

    /**
     * Respond to any enquire link we might have waiting.
     */
    public static function respondEnquireLink()
    {
        return static::getSmppClient()->respondEnquireLink();
    }

    /**
     * Reconnect to SMSC.
     * @throws \Exception
     */
    public static function reconnect()
    {
        return static::getSmppClient()->reconnect();
    }

    /**
     * Sends the PDU command to the SMSC and waits for response.
     * @param integer $id - command ID
     * @param string $pduBody - PDU body
     * @return bool|\Kstmostofa\LaravelSmpp\Pdu
     * @throws \Exception
     */
    public static function sendCommand($id, $pduBody)
    {
        return static::getSmppClient()->sendCommand($id, $pduBody);
    }

    /**
     * Prepares and sends PDU to SMSC.
     * @param \Kstmostofa\LaravelSmpp\Pdu $pdu
     */
    public static function sendPDU(\Kstmostofa\LaravelSmpp\Pdu $pdu)
    {
        return static::getSmppClient()->sendPDU($pdu);
    }

    /**
     * Waits for SMSC response on specific PDU.
     * @param integer $sequenceNumber - PDU sequence number
     * @param integer $commandID - PDU command ID
     * @return \Kstmostofa\LaravelSmpp\Pdu|bool
     * @throws \Kstmostofa\LaravelSmpp\Exceptions\SmppException
     */
    public static function readPduResponse($sequenceNumber, $commandID)
    {
        return static::getSmppClient()->readPduResponse($sequenceNumber, $commandID);
    }

    /**
     * Reads incoming PDU from SMSC.
     * @return bool|\Kstmostofa\LaravelSmpp\Pdu
     */
    public static function readPDU()
    {
        return static::getSmppClient()->readPDU();
    }

    /**
     * Reads C style null padded string from the char array.
     * @param array $ar - input array
     * @param integer $maxLength - maximum length to read.
     * @param boolean $firstRead - is this the first bytes read from array?
     * @return string.
     */
    public static function getString(&$ar, $maxLength = 255, $firstRead = false)
    {
        return static::getSmppClient()->getString($ar, $maxLength, $firstRead);
    }

    /**
     * Read a specific number of octets from the char array.
     * @param array $ar - input array
     * @param int $length
     * @return string
     */
    public static function getOctets(&$ar, $length)
    {
        return static::getSmppClient()->getOctets($ar, $length);
    }

    public static function parseTag(&$ar)
    {
        return static::getSmppClient()->parseTag($ar);
    }

    /**
     * Parse received PDU from SMSC.
     * @param \Kstmostofa\LaravelSmpp\Pdu $pdu - received PDU from SMSC.
     * @return \Kstmostofa\LaravelSmpp\DeliveryReceipt|\Kstmostofa\LaravelSmpp\Sms parsed PDU as array.
     */
    public static function parseSMS(\Kstmostofa\LaravelSmpp\Pdu $pdu)
    {
        return static::getSmppClient()->parseSMS($pdu);
    }

    /**
     * Query the SMSC about current state/status of a previous sent SMS.
     * @param string $messageID
     * @param \Kstmostofa\LaravelSmpp\Address $source
     * @return array
     * @throws \Exception
     */
    public static function queryStatus($messageID, \Kstmostofa\LaravelSmpp\Address $source)
    {
        return static::getSmppClient()->queryStatus($messageID, $source);
    }

    /**
     * Parse a timestring as formatted by SMPP v3.4 section 7.1.
     * @param string $input
     * @param boolean $newDates
     * @return mixed
     * @throws \Exception
     */
    public static function parseSmppTime($input, $newDates = true)
    {
        return static::getSmppClient()->parseSmppTime($input, $newDates);
    }

    /**
     * Split a message into multiple parts, taking the encoding into account.
     * @param string $message
     * @param integer $split
     * @param integer $dataCoding (optional)
     * @return array
     */
    public static function splitMessageString($message, $split, $dataCoding = \Kstmostofa\LaravelSmpp\SMPP::DATA_CODING_DEFAULT)
    {
        return static::getSmppClient()->splitMessageString($message, $split, $dataCoding);
    }

    /**
     * Get a CSMS reference number for sar_msg_ref_num.
     */
    public static function getCsmsReference()
    {
        return static::getSmppClient()->getCsmsReference();
    }

    /**
     * Perform the actual submit_sm call to send SMS.
     * @param \Kstmostofa\LaravelSmpp\Address $source
     * @param \Kstmostofa\LaravelSmpp\Address $destination
     * @param string $short_message
     * @param array $tags
     * @param integer $dataCoding
     * @param integer $priority
     * @param string $scheduleDeliveryTime
     * @param string $validityPeriod
     * @param string $esmClass
     * @return string message id
     * @throws \Exception
     */
    public static function submit_sm(
        \Kstmostofa\LaravelSmpp\Address $source,
        \Kstmostofa\LaravelSmpp\Address $destination,
                $short_message = null,
                $tags = null,
                $dataCoding = \Kstmostofa\LaravelSmpp\SMPP::DATA_CODING_DEFAULT,
                $priority = 0x00,
                $scheduleDeliveryTime = null,
                $validityPeriod = null,
                $esmClass = null
    ) {
        return static::getSmppClient()->submit_sm(
            $source,
            $destination,
            $short_message,
            $tags,
            $dataCoding,
            $priority,
            $scheduleDeliveryTime,
            $validityPeriod,
            $esmClass
        );
    }

    /**
     * Binds the socket and opens the session on SMSC
     * @param string $login - ESME system_id
     * @param $pass
     * @param $commandID
     * @return bool|\Kstmostofa\LaravelSmpp\Pdu
     * @throws \Exception
     */
    public static function bind($login, $pass, $commandID)
    {
        return static::getSmppClient()->bind($login, $pass, $commandID);
    }

    /**
     * Set custom configuration for the SMPP client.
     * @param array $config
     * @return void
     */
    public static function setConfig(array $config)
    {
        return static::getSmppClient()->setConfig($config);
    }

    /**
     * Send one SMS to SMSC.
     * @param \Kstmostofa\LaravelSmpp\Address $from
     * @param \Kstmostofa\LaravelSmpp\Address $to
     * @param string $message
     * @param array $tags (optional)
     * @param integer $dataCoding (optional)
     * @param integer $priority (optional)
     * @param string $scheduleDeliveryTime (optional)
     * @param string $validityPeriod (optional)
     * @return string message id
     */
    public static function sendSMS(
        $message,
        $tags = null,
        $dataCoding = \Kstmostofa\LaravelSmpp\SMPP::DATA_CODING_DEFAULT,
        $priority = 0x00,
        $scheduleDeliveryTime = null,
        $validityPeriod = null
    )
    {
        return static::getSmppClient()->sendSMS(
            $message,
            $tags,
            $dataCoding,
            $priority,
            $scheduleDeliveryTime,
            $validityPeriod
        );
    }

    /**
     * Read one SMS from SMSC.
     * @return \Kstmostofa\LaravelSmpp\DeliveryReceipt|\Kstmostofa\LaravelSmpp\Sms|bool
     */
    public static function readSMS()
    {
        return static::getSmppClient()->readSMS();
    }

    public static function setSender($sender, $ton = \Kstmostofa\LaravelSmpp\SMPP::TON_ALPHANUMERIC, $npi = \Kstmostofa\LaravelSmpp\SMPP::NPI_UNKNOWN)
    {
        return static::getSmppClient()->setSender($sender, $ton, $npi);
    }

    public static function setRecipient($recipient, $ton = \Kstmostofa\LaravelSmpp\SMPP::TON_INTERNATIONAL, $npi = \Kstmostofa\LaravelSmpp\SMPP::NPI_UNKNOWN)
    {
        return static::getSmppClient()->setRecipient($recipient, $ton, $npi);
    }

    public static function requestDLR($delivery = \Kstmostofa\LaravelSmpp\SMPP::REG_DELIVERY_SMSC_BOTH)
    {
        return static::getSmppClient()->requestDLR($delivery);
    }
}
