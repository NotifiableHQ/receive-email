<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the disk that should be used to store incoming emails.
    |
    */
    'storage-disk' => 'local',

    /*
    |--------------------------------------------------------------------------
    | Received Email Model Table
    |--------------------------------------------------------------------------
    |
    | Here you may specify the table name for the ReceivedEmail model.
    |
    */
    'model-table' => 'received_emails',

    /*
    |--------------------------------------------------------------------------
    | Email Filters
    |--------------------------------------------------------------------------
    |
    | Here you may specify the email filters that should be applied to incoming
    | emails. These filters will be applied in the order that they are listed
    | in this array. You may use the default filters or create your own.
    |
    */

    'email-filters' => [
        // \Notifiable\Filters\SenderDomainWhitelistFilter::class,
        // \Notifiable\Filters\SenderAddressWhitelistFilter::class,
        // \Notifiable\Filters\SenderDomainBlacklistFilter::class,
        // \Notifiable\Filters\SenderAddressBlacklistFilter::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sender Domain Whitelist
    |--------------------------------------------------------------------------
    |
    | Here you may specify a list of domains that are allowed to send emails.
    | If the sender's domain is not in this list, the email will be rejected.
    |
    */

    'sender-domain-whitelist' => [],

    /*
    |--------------------------------------------------------------------------
    | Sender Address Whitelist
    |--------------------------------------------------------------------------
    |
    | Here you may specify a list of email addresses that are allowed to send
    | emails. If the sender's email address is not in this list, the email
    | will be rejected.
    |
    */

    'sender-address-whitelist' => [],

    /*
    |--------------------------------------------------------------------------
    | Sender Domain Blacklist
    |--------------------------------------------------------------------------
    |
    | Here you may specify a list of domains that are not allowed to send emails.
    | If the sender's domain is in this list, the email will be rejected.
    |
    */

    'sender-domain-blacklist' => [],

    /*
    |--------------------------------------------------------------------------
    | Sender Address Blacklist
    |--------------------------------------------------------------------------
    |
    | Here you may specify a list of email addresses that are not allowed to send
    | emails. If the sender's email address is in this list, the email will be
    | rejected.
    |
    */

    'sender-address-blacklist' => [],

];
