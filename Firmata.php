<?php
/**
 * User: iMartyn
 * Date: 11/02/13
 * Revision: 0.1 (git)
 */
define('__DEBUG__', true);

/* info from the wiki :
 * This protocol uses the MIDI message format, but does not use the whole
 * protocol.  Most of the command mappings here will not be directly usable in
 * terms of MIDI controllers and synths.  It should co-exist with MIDI without
 * trouble and can be parsed by standard MIDI interpreters.  Just some of the
 * message data is used differently.
 *
 * MIDI format: http://www.harmony-central.com/MIDI/Doc/table1.html
 *
 *                              MIDI
 * type                command  channel    first byte            second byte
 *----------------------------------------------------------------------------
 * analog I/O message    0xE0   pin #      LSB(bits 0-6)         MSB(bits 7-13)
 * digital I/O message   0x90   port       LSB(bits 0-6)         MSB(bits 7-13)
 * report analog pin     0xC0   pin #      disable/enable(0/1)   - n/a -
 * report digital port   0xD0   port       disable/enable(0/1)   - n/a -
 *
 * sysex start           0xF0
 * set pin mode(I/O)     0xF4              pin # (0-127)         pin state(0=in)
 * sysex end             0xF7
 * protocol version      0xF9              major version         minor version
 * system reset          0xFF
 *
 */

require_once "php-serial/php_serial.class.php";
class Firmata
{
    /*
     * These pinmode constants happen to match the firmata pinmode values, but don't depend on that!
     */
    const PINMODE_INPUT = 0;
    const PINMODE_OUTPUT = 1;
    const PINMODE_ANALOG = 2;
    const PINMODE_PWM = 3;
    const PINMODE_SERVO = 4;
    /* PINMODE_MAXID used for message validation */
    const PINMODE_MAXID = 4;
    /* I'm using the firmata command constants here for consistency. That should not be assumed to be the case, use the
     * constants because extra processing may be done on certain ones.  If you wish to send a specific command as yet
     * unsupported, first create a github ticket, then use sendRawCommand.  If you can implement it, then submit a pull
     * request on github too!
     */
    const CMD_DIGITAL_SET = 0x90;
    const CMD_ANALOG_READ = 0xC0;
    const CMD_DIGITAL_READ = 0xD0;
    const CMD_ANALOG_SET = 0xE0;
    const CMD_SYSEX_START = 0xF0;
    const CMD_PINMODE = 0xF4;
    const CMD_SYSEX_END = 0xF7;
    const CMD_PROTOCOL_VER = 0xF7;
    const CMD_SYSTEM_RESET = 0xFF;

    const RESERVED_COMMAND = 0x00; // 2nd SysEx data byte is a chip-specific command (AVR, PIC, TI, etc).
    const ANALOG_MAPPING_QUERY = 0x69; // ask for mapping of analog to pin numbers
    const ANALOG_MAPPING_RESPONSE = 0x6A; // reply with mapping info
    const CAPABILITY_QUERY = 0x6B; // ask for supported modes and resolution of all pins
    const CAPABILITY_RESPONSE = 0x6C; // reply with supported modes and resolution
    const PIN_STATE_QUERY = 0x6D; // ask for a pin's current mode and value
    const PIN_STATE_RESPONSE = 0x6E; // reply with a pin's current mode and value
    const EXTENDED_ANALOG = 0x6F; // analog write (PWM, Servo, etc) to any pin
    const SERVO_CONFIG = 0x70; // set max angle, minPulse, maxPulse, freq
    const STRING_DATA = 0x71; // a string message with 14-bits per char
    const SHIFT_DATA = 0x75; // shiftOut config/data message (34 bits)
    const I2C_REQUEST = 0x76; // I2C request messages from a host to an I/O board
    const I2C_REPLY = 0x77; // I2C reply messages from an I/O board to a host
    const I2C_CONFIG = 0x78; // Configure special I2C settings such as power pins and delay times
    const REPORT_FIRMWARE = 0x79; // report name and version of the firmware
    const SAMPLING_INTERVAL = 0x7A; // sampling interval
    const SYSEX_NON_REALTIME = 0x7E; // MIDI Reserved for non-realtime messages
    const SYSEX_REALTIME = 0x7F; // MIDI Reserved for realtime messages

    const VALUE_DIGITAL_HIGH = 1;
    const VALUE_DIGITAL_LOW = 0;

    private $serialConnection = null;
    private $serialConnected = false;
    private $pinModes = array();

    /**
     * @param string $serialPort e.g. COM3 or /dev/ttyUSB0
     * @param string $baudRate e.g. 115200 - default is 57600
     * @throws exception passes any exception from the serial class
     */
    private function connect($serialPort, $baudRate = "57600")
    {
        try {
            $this->serialConnection = new phpSerial\phpSerial();
            $this->serialConnection->deviceSet($serialPort);
            $this->serialConnection->confBaudRate($baudRate);
            $this->serialConnection->deviceOpen();
        } catch (exception $e) {
            throw $e;
        }
        $this->serialConnected = true;
    }

    /**
     * Generates a message ready to be sent by sendRawCommand();
     * @param int   $cmd   The command as defined by the class
     * @param mixed $data  The data for the command, usually an associative array
     *   e.g. array("pin"=>1,"mode"=>Firmata::PINMODE_INPUT)
     * @return array Returns the associative array that sendRawCommand() expects
     * @throws LogicException if validation of message data fails.
     */
    private function generateMessage($cmd, $data)
    {
        if (!$this->serialConnected) {
            throw new LogicException("Firmata::generateMessage NOT CONNECTED!  Please call Firmata::connect() first");
        }
        switch ($cmd) {
            case Firmata::CMD_PINMODE:
                $validation = (array_key_exists("pin", $data) && !empty($data["pin"]) && is_numeric($data["pin"]) &&
                    array_key_exists("mode", $data) && !empty($data["mode"]) && is_numeric($data["mode"]) &&
                    $data["mode"] >= Firmata::PINMODE_INPUT && $data["mode"] <= Firmata::PINMODE_MAXID
                );
                if (!$validation) {
                    throw new LogicException("Every PINMODE command must have a pin and a mode!");
                }
                switch ($data) {
                    case Firmata::PINMODE_INPUT :
                        $messagePacket = array(0xF4, $data["pin"], $data["mode"]);
                        return $messagePacket;
                }
                break;
            case Firmata::CMD_DIGITAL_SET :
                $validation = (array_key_exists("pin", $data) && !empty($data["pin"]) && is_numeric($data["pin"]) &&
                    array_key_exists("value", $data) && !empty($data["value"]) && is_numeric($data["value"])
                );
                if (!$validation) {
                    throw new LogicException("Every DIGITAL WRITE command must have a numeric pin and value!");
                }
                $validation = array_key_exists($data["pin"], $this->pinModes) &&
                    $this->pinModes[$data["pin"]] == Firmata::PINMODE_OUTPUT;
                if (!$validation) {
                    throw new LogicException("PIN " . $data["pin"] . " is not set to output");
                }
                return array(0x90 | $data["pin"], $data['value'] & 0x7F, ($data['value'] >> 7) & 0x7F);
                break;
            case Firmata::CMD_ANALOG_SET :
                $validation = (array_key_exists("pin", $data) && !empty($data["pin"]) && is_numeric($data["pin"]) &&
                    array_key_exists("value", $data) && !empty($data["value"]) && is_numeric($data["value"])
                );
                if (!$validation) {
                    throw new LogicException("Every ANALOG WRITE command must have a numeric pin and value!");
                }
                $validation = array_key_exists($data["pin"], $this->pinModes) &&
                    $this->pinModes[$data["pin"]] == Firmata::PINMODE_PWM;
                if (!$validation) {
                    throw new LogicException("PIN " . $data["pin"] . " is not set to pwm");
                }
                return array(0xC0 | $data["pin"], $data['value'] & 0x7F, ($data['value'] >> 7) & 0x7F);
                break;
        }
        throw new LogicException("Unknown message requested by an internal function! Oh hell, even I wasn't expecting that!");
    }

    /**
     * Uses the php serial class to send a message to the microcontroller
     * @param $data
     * @return mixed Either true (message successfully sent); the data if a retun expected or false (something went wrong)
     */
    function sendRawCommand($data)
    {
        if (!$this->serialConnected) {
            throw new LogicException("Firmata::sendRawCommand NOT CONNECTED!  Please call Firmata::connect() first");
        }
        if (defined('__DEBUG__')) {
            //Might want to log this somewhere
            return TRUE;
        }
        if ($data) {
            //Stop the warnings
        }
        return false;
    }

    /**
     * Polls the serial port for data and returns an array of bytes for processing - usually 3 bytes but if sysex,
     * however many are required.
     * @return array of bytes.
     * @throws LogicException
     */
    function receiveRawCommand()
    {
        if (!$this->serialConnected) {
            throw new LogicException("Firmata::receiveRawCommand NOT CONNECTED!  Please call Firmata::connect() first");
        }
        $byteRead = $this->serialConnection->readPort(1);
        switch ($byteRead) {
            case 0xF9 : // REPORT_VERSION - 3 bytes including the F9
                $returnMessage = array(0xF9, $this->serialConnection->readPort(1), $this->serialConnection->readPort(1));
                return $returnMessage;
                break;
            case 0xF0 : // SYSEX_START - multiple bytes, until SYSEX_END (F7)
                $returnMessage[] = 0xF0;
                while ($byteRead = $this->serialConnection->readPort(1) && $byteRead != 0xF7) {
                    $returnMessage[] = $byteRead;
                }
                $returnMessage[] = 0xF7;
                return $returnMessage;
                break;
            default:
                // midi message, assume.  3 bytes
                $returnMessage = array($byteRead, $this->serialConnection->readPort(1), $this->serialConnection->readPort(1));
                return $returnMessage;
                break;
        }
    }

    /**
     * gets the Firmata version
     * @throws LogicException
     * @return mixed data returned by the getversion command
     */
    function getVersion()
    {
        if (!$this->serialConnected) {
            throw new LogicException("Firmata::getVersion NOT CONNECTED!  Please call Firmata::connect() first");
        }
        $message = $this->generateMessage(Firmata::CMD_SYSEX_START, null);
        if (!$this->sendRawCommand($message)) {
            $this->reset();
            throw new LogicException("Firmata::getVersion Error sending sysex_start! Reset command sent, hope that helps.");
        }
        $message = $this->generateMessage(Firmata::REPORT_FIRMWARE, null);
        if (!$version = $this->sendRawCommand($message)) {
            $endmessage = $this->generateMessage(Firmata::CMD_SYSEX_END, null);
            if (!$this->sendRawCommand($endmessage)) {
                $this->reset();
                throw new LogicException("Firmata::getVersion Error sending sysex_end! Reset command sent, hope that helps.");
            }
            throw new LogicException("Firmata::getVersion Error sending sysex message! Sysex_end sent, hope that helps.");
        }
        $message = $this->generateMessage(Firmata::CMD_SYSEX_END, null);
        if (!$this->sendRawCommand($message)) {
            $this->reset();
            throw new LogicException("Firmata::getVersion Error sending sysex_end! Reset command sent, hope that helps.");
        }
    }

    /**
     * Resets the device
     * @throws LogicException
     */
    function reset()
    {
        if (!$this->serialConnected) {
            throw new LogicException("Firmata::reset NOT CONNECTED!  Please call Firmata::connect() first");
        }
        $message = $this->generateMessage(Firmata::CMD_SYSTEM_RESET, null);
        if ($this->sendRawCommand($message)) {
            throw new LogicException("Firmata::reset Error sending reset message! Assuming disconnected.");
        }
    }

    /**
     * Sets the pin mode according to the constants, including generating the message and sending it.
     * @param int $pin
     * @param int $mode
     * @return bool From the SendRawCommand
     * @throws LogicException
     */
    function pinMode($pin, $mode)
    {
        if (!$this->serialConnected) {
            throw new LogicException("Firmata::pinMode NOT CONNECTED!  Please call Firmata::connect() first");
        }
        $message = $this->generateMessage(Firmata::CMD_PINMODE, array("pin" => $pin, "mode" => $mode));
        if (array_key_exists('command', $message) && !empty($message["command"])) {
            if ($this->sendRawCommand($message)) {
                $pinModes[$pin] = $mode;
                return true;
            } else {
                return false;
            }
        }
        throw new LogicException("Firmata::generateMessage failed, perhaps invalid arguments?");
    }

    /**
     * Reads from a pin, but checks that pin is already set up to read mode.
     * @param int $pin
     * @return bool
     * @throws LogicException
     */
    function digitalRead($pin)
    {
        if (!$this->serialConnected) {
            throw new LogicException("Firmata::digitalRead NOT CONNECTED!  Please call Firmata::connect() first");
        }
        if (!in_array($pin, $this->pinModes) || $this->pinModes[$pin] !== Firmata::PINMODE_INPUT) {
            throw new LogicException("You cannot read from a pin until such time as it is set to input mode.");
        } else {
            /* TODO: Actual reading code here */
            return true;
        }
    }

    /**
     * Writes to a pin - high or low
     * @param int $pin
     * @param int $value
     * @return bool
     * @throws LogicException
     * @throws OutOfBoundsException
     */
    function digitalWrite($pin, $value)
    {
        if (!$this->serialConnected) {
            throw new LogicException("Firmata::digitalWrite NOT CONNECTED!  Please call Firmata::connect() first");
        }
        if (!in_array($pin, $this->pinModes) || $this->pinModes[$pin] !== Firmata::PINMODE_OUTPUT) {
            throw new LogicException("You cannot write to a pin until such time as it is set to output mode.");
        } elseif ($value !== Firmata::VALUE_DIGITAL_HIGH || $value !== Firmata::VALUE_DIGITAL_LOW) {
            throw new OutOfBoundsException("digitalWrite value must be either Firmata::VALUE_DIGITAL_HIGH or Firmata::VALUE_DIGITAL_LOW");
        } else {
            /* TODO: Actual writing code here */
            return true;
        }
    }
}
