<?php

declare(strict_types=1);

use Farzai\DatasetKit\Publish\SigV4;

/*
 * Against AWS's own published examples, so the signer is checked by
 * something other than itself.
 */

test('the S3 documentation example: GET object with a Range header', function () {
    // docs.aws.amazon.com/AmazonS3/latest/API/sig-v4-header-based-auth.html, "Example: GET Object".
    $signer = new SigV4('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1', 's3');
    $signed = $signer->sign('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', ['Range' => 'bytes=0-9'], hash('sha256', ''), new DateTimeImmutable('2013-05-24T00:00:00Z'));

    $this->assertSame(
        'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request,SignedHeaders=host;range;x-amz-content-sha256;x-amz-date,Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
        $signed['Authorization'],
    );
    $this->assertSame('20130524T000000Z', $signed['x-amz-date']);
    $this->assertSame(hash('sha256', ''), $signed['x-amz-content-sha256']);
});

test('the S3 documentation example: PUT object', function () {
    // Same page, "Example: PUT Object": body "Welcome to Amazon S3.", storage class header.
    $signer = new SigV4('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1', 's3');
    $body = 'Welcome to Amazon S3.';
    $signed = $signer->sign('PUT', 'https://examplebucket.s3.amazonaws.com/test$file.text', [
        'Date' => 'Fri, 24 May 2013 00:00:00 GMT',
        'x-amz-storage-class' => 'REDUCED_REDUNDANCY',
    ], hash('sha256', $body), new DateTimeImmutable('2013-05-24T00:00:00Z'));

    $this->assertSame(
        'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request,SignedHeaders=date;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class,Signature=98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd',
        $signed['Authorization'],
    );
});

test('the secret never appears in what the signer returns or dumps', function () {
    $signer = new SigV4('AKID', 'SENTINEL_SECRET_DO_NOT_LOG', 'auto', 's3');
    $signed = $signer->sign('PUT', 'https://acct.r2.cloudflarestorage.com/bucket/k', [], hash('sha256', 'x'), new DateTimeImmutable('2026-10-14T00:00:00Z'));
    $this->assertStringNotContainsString('SENTINEL_SECRET_DO_NOT_LOG', implode("\n", $signed));
    $this->assertStringNotContainsString('SENTINEL_SECRET_DO_NOT_LOG', print_r($signer, true));
    ob_start();
    var_dump($signer);
    $this->assertStringNotContainsString('SENTINEL_SECRET_DO_NOT_LOG', (string) ob_get_clean());
    try {
        $signer->sign('PUT', 'not a url', [], 'x', new DateTimeImmutable());
        $this->fail('a bad url must throw');
    } catch (InvalidArgumentException $e) {
        $this->assertStringNotContainsString('SENTINEL_SECRET_DO_NOT_LOG', $e->getTraceAsString() . $e->getMessage());
    }
});
