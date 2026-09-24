<?php

namespace NoriaLabs\Send\Resources;

class Workspaces extends Resource
{
    /**
     * An API key reaches exactly one.
     *
     * @return array<string, mixed>
     */
    public function list(): array
    {
        return $this->send->request('GET', '/v1/workspaces');
    }
}
