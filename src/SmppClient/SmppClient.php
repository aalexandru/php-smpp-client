<?php
/**
 * SMPP Client
 *
 * @author     Andrei ALEXANDRU <aalexandru@streamwide.ro>
 */

namespace SmppClient;

require_once 'Net/SMPP.php';
require_once 'Net/SMPP/Command.php';
require_once 'Net/Socket.php';

class SmppClient
{
    const BIND_TIMEOUT = 60; // seconds
    const CLIENT_TIMEOUT = 10; // seconds

    private $debug = false;

    private $host;
    private $port;
    private $user;
    private $pass;
    private $systemId;
    private $acceptedIps;

    private $socket;
    private $client;
    private $isClientConnected = false;

    /**
     * @param string $host
     * @param int $port
     * @param string $user
     * @param string $pass
     * @param string $systemId
     * @param array $acceptedIps
     */
    function __construct($host, $port, $user, $pass, $systemId, $acceptedIps)
    {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->pass = $pass;
        $this->systemId = $systemId;
        $this->acceptedIps = $acceptedIps;
    }

    /**
     * @param bool $status
     */
    public function debug($status)
    {
        $this->debug = is_bool($status) ? $status : false;
    }

    public function start()
    {
        $this->startTime = microtime();

        $this->socket = socket_create_listen($this->port);
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => self::BIND_TIMEOUT, 'usec' => 0));
        while (socket_getsockname($this->socket, $this->host)) {
            $this->log("Server listening on {$this->host}:{$this->port}");
            $client = socket_accept($this->socket);

            if (is_null($client)) {
                return false;
            }

            socket_getpeername($client, $raddr, $rport);

            if (!in_array($raddr, $this->acceptedIps) && !$this->isClientConnected) {
                $this->log("Denied Connection from $raddr:$rport");
                return false;
            }

            $this->client = $client;

            $this->log("Received Connection from $raddr:$rport");
            return $this->receive();
        }

        $this->stop();
    }

    private function log($message)
    {
        if ($this->debug) {
            $timestamp = date("Y-m-d H:i:s", time());
            print "[FakeSMSC {$timestamp}] $message\n";
            flush();
            usleep(100);
        }
    }

    public function receive($command = null, $content = null)
    {
        while ($buffer = socket_read($this->client, 4048)) {
            socket_set_option($this->client, SOL_SOCKET, SO_RCVTIMEO, array('sec' => self::CLIENT_TIMEOUT, 'usec' => 0));
            $pdu = $this->handle($this->client, $buffer);
            if (is_null($command) || is_null($content)) {
                return $pdu;
            }
            if ($pdu->command == $command) {
                $shortMessage = str_replace("\0", "", $pdu->short_message);
                if ($shortMessage == $content) {
                    $this->log("Matched content: {$content}");
                    return $pdu;
                }
                $this->log("Didn't match content: {$shortMessage} != {$content}");
            }
        }
    }

    private function handle($client, $buffer)
    {
        $pdu = \Net_SMPP::parsePDU($buffer);
        $this->log("Received command {$pdu->command}: " . $this->pduToString($pdu));
        switch ($pdu->command) {
            case 'bind_transceiver':
                if ($pdu->system_id == $this->user && $pdu->password == $this->pass) {
                    $resp = \Net_SMPP_PDU::factory('bind_transceiver_resp', array(
                        'status' => 0,
                        'system_id' => $this->systemId,
                        'sequence' => $pdu->sequence,
                    ));
                    socket_send($client, $resp->generate(), 4048, MSG_DONTROUTE);
                    $this->log('Sent bind_transceiver_resp');
                    $this->isClientConnected = true;
                }
                break;
            case 'enquire_link':
                $resp = \Net_SMPP_PDU::factory('enquire_link_resp', array(
                    'sequence' => $pdu->sequence,
                ));
                socket_send($client, $resp->generate(), 4048, MSG_DONTROUTE);
                $this->log('Sent enquire_link_resp');
                break;
            case 'submit_sm':
                $resp = \Net_SMPP_PDU::factory('submit_sm_resp', array(
                    'sequence' => $pdu->sequence,
                ));
                socket_send($client, $resp->generate(), 4048, MSG_DONTROUTE);
                $this->log('Sent submit_sm_resp');
                break;
            case 'unbind':
                $this->handleUnbind($client);
                break;
        }

        return $pdu;
    }

    private function pduToString($pdu)
    {
        $vars = array();
        foreach (get_object_vars($pdu) as $key => $value) {
            array_push($vars, $key . '=' . $value);
        }
        return implode(', ', $vars);
    }

    private function handleUnbind($client)
    {
        $resp = \Net_SMPP_PDU::factory('unbind_resp', array());
        socket_send($client, $resp->generate(), 4048, MSG_DONTROUTE);
        $this->log('Sent unbind_resp');
        socket_close($client);

        $this->isClientConnected = false;
        unset($this->client);
    }

    public function stop()
    {
        if ($this->isClientConnected) {
            $this->handleUnbind($this->client);
        }
        if (isset($this->socket)) {
            socket_shutdown($this->socket);
            socket_close($this->socket);
            unset($this->socket);
        }
        $this->log('Server stopped');
    }

    public function isClientConnected()
    {
        return $this->isClientConnected;
    }

    public function send($request)
    {
        $sendRequest = \Net_SMPP_PDU::factory('deliver_sm', array(
            'short_message' => $request['short_message'],
            'sm_length' => strlen($request['short_message']),
            'source_addr' => $request['source_addr'],
            'destination_addr' => $request['destination_addr'],
        ));
        socket_send($this->client, $sendRequest->generate(), 4048, MSG_DONTROUTE);
        $this->log('Sent command deliver_sm');
    }
}
