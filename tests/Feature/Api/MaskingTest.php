<?php

use App\Services\Security\SensitiveDataMasker;

/*
| D8 + security review H2: credentials must never reach storage, whatever
| naming style the source system uses, without redacting ordinary fields.
*/

it('redacts credential keys in every naming style', function (string $key) {
    expect(SensitiveDataMasker::forStorage()->isSensitive($key))->toBeTrue();
})->with([
    'APIToken', 'DBPassword', 'SMTPPassword', 'AWSSecretKey', 'password2', 'cvv2', 'OTPCode', 'PINCode',
    'pwd', 'passphrase', 'JWT', 'credentials', 'cookie', 'Set-Cookie', 'creditCard', 'dbpassword',
    'mysecret', 'accessToken', 'user_password', 'billingCardNumber', 'Authorization', 'private-key',
]);

it('keeps ordinary fields that merely contain those letters', function (string $key) {
    expect(SensitiveDataMasker::forStorage()->isSensitive($key))->toBeFalse();
})->with(['shipping', 'secretary', 'opinion', 'company', 'tokenizer_version', 'pinned', 'spin_count', 'status', 'national_id']);

it('redacts credential values whatever their key', function () {
    $out = SensitiveDataMasker::forStorage()->mask([
        'header' => 'Bearer abcdefghijklmnop',
        'nested' => ['x' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjMifQ.c2lnbmF0dXJl'],
        'key' => 'alh_'.str_repeat('a', 44),
        'word' => 'Bearer',
    ]);

    expect($out)->toBe([
        'header' => '[REDACTED]',
        'nested' => ['x' => '[REDACTED]'],
        'key' => '[REDACTED]',
        'word' => 'Bearer',
    ]);
});

it('masks personal data on display but does not redact it at storage', function () {
    expect((new SensitiveDataMasker)->mask(['national_id' => '1', 'iban' => 'SA0']))->toBe(['national_id' => '********', 'iban' => '********'])
        ->and(SensitiveDataMasker::forStorage()->mask(['national_id' => '1']))->toBe(['national_id' => '1']);
});
