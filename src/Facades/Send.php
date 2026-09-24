<?php

namespace NoriaLabs\Send\Facades;

use Illuminate\Support\Facades\Facade;
use NoriaLabs\Send\Resources\ApiKeys;
use NoriaLabs\Send\Resources\Domains;
use NoriaLabs\Send\Resources\Emails;
use NoriaLabs\Send\Resources\Messages;
use NoriaLabs\Send\Resources\Workspaces;
use NoriaLabs\Send\Resources\Senders;
use NoriaLabs\Send\Resources\Sms;
use NoriaLabs\Send\Resources\Suppressions;
use NoriaLabs\Send\Resources\Templates;
use NoriaLabs\Send\Resources\Webhooks;
use NoriaLabs\Send\Send as Client;

/**
 * @method static Emails emails()
 * @method static Sms sms()
 * @method static Messages messages()
 * @method static Domains domains()
 * @method static Senders senders()
 * @method static Templates templates()
 * @method static Suppressions suppressions()
 * @method static Webhooks webhooks()
 * @method static Workspaces workspaces()
 * @method static ApiKeys apiKeys()
 *
 * @see Client
 */
class Send extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
