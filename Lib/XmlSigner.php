<?php
namespace FacturaScripts\Plugins\DianFactCol\Lib;

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

class XmlSigner
{
    public static function sign(string $xml, string $certPath, string $password): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadXML($xml);
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;

        $objDSig = new XMLSecurityDSig();
        $objDSig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $objDSig->addReference(
            $doc,
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature']
        );

        $objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $objKey->loadKey($certPath, true);

        $objDSig->sign($objKey);
        $objDSig->add509Cert(file_get_contents($certPath));

        $objDSig->appendSignature($doc->documentElement);
        return $doc->saveXML();
    }
}