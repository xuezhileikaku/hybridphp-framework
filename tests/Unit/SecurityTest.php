<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use HybridPHP\Core\Security\EncryptionService;
use HybridPHP\Core\Security\DataMasking;
use function Amp\async;

/**
 * Security unit tests
 */
class SecurityTest extends TestCase
{
    private EncryptionService $encryption;
    private string $testKey;

    protected function setUp(): void
    {
        $this->testKey = str_repeat('a', 32); // 32 character key
        $this->encryption = new EncryptionService($this->testKey);
    }

    public function testEncryptionServiceCreation(): void
    {
        $this->assertInstanceOf(EncryptionService::class, $this->encryption);
    }

    public function testEncryptionServiceThrowsForShortKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Encryption key must be at least 32 characters long');
        
        new EncryptionService('short');
    }

    public function testEncryptAndDecrypt(): void
    {
        $result = async(function () {
            $plaintext = 'Hello, World!';
            $encrypted = $this->encryption->encrypt($plaintext)->await();
            $decrypted = $this->encryption->decrypt($encrypted)->await();
            
            return [$plaintext, $encrypted, $decrypted];
        })->await();

        $this->assertNotEquals($result[0], $result[1]);
        $this->assertEquals($result[0], $result[2]);
    }

    public function testEncryptProducesDifferentOutputs(): void
    {
        $result = async(function () {
            $plaintext = 'Same text';
            $encrypted1 = $this->encryption->encrypt($plaintext)->await();
            $encrypted2 = $this->encryption->encrypt($plaintext)->await();
            
            return [$encrypted1, $encrypted2];
        })->await();

        // Due to random IV, same plaintext should produce different ciphertext
        $this->assertNotEquals($result[0], $result[1]);
    }

    public function testDecryptWithWrongKeyFails(): void
    {
        $this->expectException(\RuntimeException::class);
        
        async(function () {
            $plaintext = 'Secret data';
            $encrypted = $this->encryption->encrypt($plaintext)->await();
            
            $wrongKeyEncryption = new EncryptionService(str_repeat('b', 32));
            $wrongKeyEncryption->decrypt($encrypted)->await();
        })->await();
    }

    public function testGenerateKey(): void
    {
        $key = $this->encryption->generateKey();
        
        $this->assertEquals(64, strlen($key)); // 32 bytes = 64 hex chars
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $key);
    }

    public function testHash(): void
    {
        $data = 'password123';
        $hash = $this->encryption->hash($data);
        
        $this->assertNotEquals($data, $hash);
        $this->assertStringStartsWith('$argon2id$', $hash);
    }

    public function testVerifyHash(): void
    {
        $data = 'password123';
        $hash = $this->encryption->hash($data);
        
        $this->assertTrue($this->encryption->verifyHash($data, $hash));
        $this->assertFalse($this->encryption->verifyHash('wrongpassword', $hash));
    }

    public function testHashWithSalt(): void
    {
        $data = 'password123';
        $salt = 'random_salt';
        
        $hash = $this->encryption->hash($data, $salt);
        
        $this->assertTrue($this->encryption->verifyHash($data, $hash, $salt));
        $this->assertFalse($this->encryption->verifyHash($data, $hash)); // Without salt
    }

    public function testGenerateSecureRandom(): void
    {
        $random1 = $this->encryption->generateSecureRandom(16);
        $random2 = $this->encryption->generateSecureRandom(16);
        
        $this->assertEquals(32, strlen($random1)); // 16 bytes = 32 hex chars
        $this->assertNotEquals($random1, $random2);
    }

    public function testMaskSensitiveData(): void
    {
        $data = '1234567890123456';
        $masked = $this->encryption->maskSensitiveData($data, 4);
        
        $this->assertEquals('1234********3456', $masked);
    }

    public function testMaskSensitiveDataShortString(): void
    {
        $data = '1234';
        $masked = $this->encryption->maskSensitiveData($data, 4);
        
        $this->assertEquals('****', $masked);
    }

    public function testKeyRotation(): void
    {
        $newKey = str_repeat('c', 32);
        $this->encryption->rotateKey($newKey);
        
        $history = $this->encryption->getKeyRotationHistory();
        
        $this->assertCount(1, $history);
        $this->assertArrayHasKey('key_hash', $history[0]);
        $this->assertArrayHasKey('rotated_at', $history[0]);
    }

    public function testKeyRotationThrowsForShortKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        
        $this->encryption->rotateKey('short');
    }

    public function testDecryptWithHistoricalKeys(): void
    {
        $result = async(function () {
            // Encrypt with original key
            $plaintext = 'Secret data';
            $encrypted = $this->encryption->encrypt($plaintext)->await();
            
            // Rotate to new key
            $this->encryption->rotateKey(str_repeat('d', 32));
            
            // Should still be able to decrypt with historical key
            $decrypted = $this->encryption->decryptWithHistoricalKeys($encrypted)->await();
            
            return [$plaintext, $decrypted];
        })->await();

        $this->assertEquals($result[0], $result[1]);
    }

    public function testEncryptEmptyString(): void
    {
        $result = async(function () {
            $plaintext = '';
            $encrypted = $this->encryption->encrypt($plaintext)->await();
            $decrypted = $this->encryption->decrypt($encrypted)->await();
            
            return $decrypted;
        })->await();

        $this->assertEquals('', $result);
    }

    public function testEncryptLongString(): void
    {
        $result = async(function () {
            $plaintext = str_repeat('A', 10000);
            $encrypted = $this->encryption->encrypt($plaintext)->await();
            $decrypted = $this->encryption->decrypt($encrypted)->await();
            
            return [$plaintext, $decrypted];
        })->await();

        $this->assertEquals($result[0], $result[1]);
    }

    public function testEncryptSpecialCharacters(): void
    {
        $result = async(function () {
            $plaintext = "Special chars: !@#$%^&*()_+-=[]{}|;':\",./<>?`~\n\t\r";
            $encrypted = $this->encryption->encrypt($plaintext)->await();
            $decrypted = $this->encryption->decrypt($encrypted)->await();
            
            return [$plaintext, $decrypted];
        })->await();

        $this->assertEquals($result[0], $result[1]);
    }

    public function testEncryptUnicodeString(): void
    {
        $result = async(function () {
            $plaintext = "Unicode: 你好世界 🌍 مرحبا العالم";
            $encrypted = $this->encryption->encrypt($plaintext)->await();
            $decrypted = $this->encryption->decrypt($encrypted)->await();
            
            return [$plaintext, $decrypted];
        })->await();

        $this->assertEquals($result[0], $result[1]);
    }

    public function testDecryptInvalidData(): void
    {
        $this->expectException(\RuntimeException::class);
        
        async(function () {
            $this->encryption->decrypt('invalid-base64-data!!!')->await();
        })->await();
    }

    public function testMaskSensitiveDataWithDifferentVisibleChars(): void
    {
        $data = '1234567890';
        
        $masked2 = $this->encryption->maskSensitiveData($data, 2);
        $masked3 = $this->encryption->maskSensitiveData($data, 3);
        
        $this->assertEquals('12******90', $masked2);
        $this->assertEquals('123****890', $masked3);
    }
}
