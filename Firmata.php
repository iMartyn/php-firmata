<?php
/**
 * User: iMartyn
 * Date: 11/02/13
 * Revision: 0.1 (git)
 */
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
    const CMD_PINMODE = 0xF4;

    /**
     * Generates a message ready to be sent by sendRawCommand();
     * @param $cmd  int   The command as defined by the class
     * @param $data mixed The data for the command, usually an associative array
     *   e.g. array("pin"=>1,"mode"=>Firmata::PINMODE_INPUT)
     * @return array Returns the associative array that sendRawCommand() expects
     * @throws LogicException if validation of message data fails.
     */
    private function generateMessage($cmd,$data) {
        switch ($cmd) {
            case Firmata::CMD_PINMODE:
                $validation = (array_key_exists("pin",$data) && !empty($data["pin"]) && is_numeric($data["pin"]) &&
                    array_key_exists("mode",$data) && !empty($data["mode"]) && is_numeric($data["mode"]) &&
                    $data["mode"] >= Firmata::PINMODE_INPUT && $data["mode"] <= Firmata::PINMODE_MAXID
                );
                if (!$validation) {
                    throw new LogicException("Every PINMODE command must have a pin and a mode!");
                }
                switch ($data) {
                    case Firmata::PINMODE_INPUT :
                        $messagePacket = array(
                            "command"=>0xF4,
                            "channel"=>$data["pin"],
                            "byte1"=>$data["mode"],
                            "byte2"=>NULL
                        );
                        return $messagePacket;
                }
                break;
        }
        throw new LogicException("Unknown message requested by an internal function! Oh hell, even I wasn't expecting that!");
    }

    /**
     * Uses the php serial class to send a message to the microcontroller
     * @param $data
     * @return bool Either true (message successfully sent) or false (something went wrong)
     */
    function sendRawCommand($data) {
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
     * Sets the pin mode according to the constants, including generating the message and sending it.
     * @param $pin
     * @param $mode
     * @return bool From the SendRawCommand
     * @throws LogicException
     */
    function pinMode($pin,$mode) {
        $message = $this->generateMessage(Firmata::CMD_PINMODE,array("pin"=>$pin,"mode"=>$mode));
        if (array_key_exists('command',$message) && !empty($message["command"])) {
            return $this->sendRawCommand($message);
        }
        throw new LogicException("Firmata::generateMessage failed, perhaps invalid arguments?");
    }
}
