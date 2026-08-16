<?php
use PHPUnit\Framework\TestCase;
class SanityTest extends TestCase {
    public function testTrue(){ $this->assertTrue(true); }
    public function testNoLicenseKeys(){
        $content = file_get_contents(__DIR__.'/../../application/config/windels.php');
        $this->assertStringNotContainsString('PURCHASE_CODE', $content);
        $this->assertStringNotContainsString('LICENSE_SERVER', $content);
    }
    public function testSecureHttpVerifiesTls(){
        $content = file_get_contents(__DIR__.'/../../application/libraries/SecureHttpClient.php');
        $this->assertStringContainsString('CURLOPT_SSL_VERIFYPEER', $content);
        $this->assertStringNotContainsString('VERIFYPEER => FALSE', $content);
        $this->assertStringNotContainsString('VERIFYPEER, false', strtolower($content));
    }
}
