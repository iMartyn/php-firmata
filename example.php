<?php

set_magic_quotes_runtime(0);
require_once("Firmata.php");
//require_once("myserial.php");
//$serial = new mySerial;

// First we must specify the device. This works on both linux and windows (if
// your linux serial device is /dev/ttyS0 for COM1, etc)
//$serial->deviceSet("/dev/ttyACM0");

// We can change the baud rate, parity, length, stop bits, flow control
//$serial->confBaudRate(57600);

/*$serial->confCharacterLength(8);
$serial->confParity('none');
$serial->confStopBits(1);*/
//$serial->confFlowControl("none");

// Then we need to open it
//$serial->deviceOpen();

// To write into
#$serial->sendMessage("Hello !");

// Or to read from
//$read = $serial->readPort(1,2048*255);
//var_dump($read);


$device = new Firmata();
$device->connect('/dev/ttyACM0');
$device->getVersion();
while (true) {
    $device->receiveRawCommand();
};


//$device->serialConnection->sendMessage(pack('C', array(0xF0,0x79,0xF7)),0);
//$device->serialConnection->serialFlush();
//while (true) {
//    $data = $serial->readChar();
//    if ($data !== '') {
//        var_dump($data);
//    }
//}
/*


 */