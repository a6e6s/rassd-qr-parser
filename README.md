# RASSD QR Code Parser

A simple PHP library to parse and extract data from RASSD GS1 QR codes.

## Installation

You can install the package via Composer:

```bash
composer require a6e6s/rassd-qr-parser
```

## Usage
Here is a basic example of how to use the parser.

```bash
<?php

require __DIR__ . '/vendor/autoload.php';

use A6e6s\RassdQrParser\RassdQrParser;

$qr = '01062810860101121727040110114487921215645645465456';
$parser = new RassdQrParser($qr);

if ($parser->isValid()) {
    print_r($parser->toArray());
} else {
    echo "Failed to parse QR code.";
}

```



## License
This package is licensed under the MIT License.

