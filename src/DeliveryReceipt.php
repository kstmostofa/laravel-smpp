<?php


namespace Kstmostofa\LaravelSmpp;

use DateTime;

/**
 * An extension of a SMS, with data embedded into the message part of the SMS.
 * @author mostofa122@gmail.com
 */
class DeliveryReceipt extends Sms
{
    // Parsed DLR data
    public $messageId;
    public $status;
    public $finalDate;
    public $errorCode;

    // Raw DLR data
    public $id;
    public $sub;
    public $dlvrd;
    public $submitDate;
    public $doneDate;
    public $stat;
    public $err;
    public $text;

    /**
     * Parse a delivery receipt formatted as specified in SMPP v3.4 - Appendix B
     * It accepts all chars except space as the message id
     *
     * @throws \InvalidArgumentException
     */
    public function parseDeliveryReceipt()
    {
        $pattern = '/^id:(?P<id>[^ ]+) sub:(?P<sub>\d{1,3}) dlvrd:(?P<dlvrd>\d{3}) submit date:(?P<submitDate>\d{10,12}) done date:(?P<doneDate>\d{10,12}) stat:(?P<stat>[A-Z ]{7}) err:(?P<err>\d{2,3}) text:(?P<text>.*)$/si';
        $numMatches = preg_match($pattern, $this->message, $matches);

        if ($numMatches == 0) {
            // Try a more flexible regex for cases where 'text' might be missing or format varies slightly
            $pattern = '/id:(?P<id>\S+)\s+sub:(?P<sub>\d+)\s+dlvrd:(?P<dlvrd>\d+)\s+submit date:(?P<submitDate>\d+)\s+done date:(?P<doneDate>\d+)\s+stat:(?P<stat>\w+)\s+err:(?P<err>\d+)/i';
            $numMatches = preg_match($pattern, $this->message, $matches);
            if ($numMatches == 0) {
                throw new \InvalidArgumentException('Could not parse delivery receipt: ' . $this->message . "\n" . bin2hex($this->body));
            }
        }

        $this->id = $matches['id'];
        $this->sub = $matches['sub'];
        $this->dlvrd = $matches['dlvrd'];
        $this->submitDate = $matches['submitDate'];
        $this->doneDate = $matches['doneDate'];
        $this->stat = $matches['stat'];
        $this->err = $matches['err'];
        $this->text = $matches['text'] ?? ''; // Handle missing text part

        // Map to user-friendly properties
        $this->messageId = $this->id;
        $this->status = $this->stat;
        $this->errorCode = $this->err;

        // Convert dates
        if ($this->doneDate) {
            $dp = str_split($this->doneDate, 2);
            // YYMMDDHHII[SS]
            $year = $dp[0];
            $month = $dp[1];
            $day = $dp[2];
            $hour = $dp[3];
            $minute = $dp[4];
            $second = $dp[5] ?? '00';
            $this->finalDate = DateTime::createFromFormat('y-m-d H:i:s', "$year-$month-$day $hour:$minute:$second");
        } else {
            $this->finalDate = null;
        }
    }
}