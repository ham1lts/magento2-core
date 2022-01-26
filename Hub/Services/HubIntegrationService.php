<?php

namespace PlugHacker\PlugCore\Hub\Services;

use PlugHacker\PlugCore\Hub\Aggregates\InstallToken;
use PlugHacker\PlugCore\Hub\Factories\HubCommandFactory;
use PlugHacker\PlugCore\Hub\Factories\InstallTokenFactory;
use PlugHacker\PlugCore\Hub\Repositories\InstallTokenRepository;
use PlugHacker\PlugCore\Hub\ValueObjects\HubInstallToken;
use PlugHacker\PlugCore\Kernel\Abstractions\AbstractModuleCoreSetup as MPSetup;
use Unirest\Request;

final class HubIntegrationService
{
    /**
     *
     * @param  $installSeed
     * @return \PlugHacker\PlugCore\Hub\ValueObjects\HubInstallToken
     */
    public function startHubIntegration($installSeed)
    {
        $tokenRepo = new InstallTokenRepository();

        $enabledTokens = $tokenRepo->listEntities(0, false);

        //expire all tokens
        foreach ($enabledTokens as $enabledToken) {
            $enabledToken->setExpireAtTimestamp(
                $enabledToken->getCreatedAtTimestamp() - 1000
            );
            $tokenRepo->save($enabledToken);
        }

        $installFactory = new InstallTokenFactory();
        $installToken = $installFactory->createFromSeed($installSeed);

        $tokenRepo->save($installToken);

        return $installToken->getToken();
    }

    public function endHubIntegration(
        $installToken,
        $authorizationCode,
        $hubCallbackUrl = null,
        $webhookUrl = null
    ) {
        $tokenRepo = new InstallTokenRepository();

        $installToken = $tokenRepo->findByPlugId(new HubInstallToken($installToken));

        if (is_a($installToken, InstallToken::class)
            && !$installToken->isExpired()
            && !$installToken->isUsed()
        ) {
            $body = [
                "code" => $authorizationCode
            ];

            if ($hubCallbackUrl) {
                $body['hub_callback_url'] = $hubCallbackUrl;
            }

            if ($webhookUrl) {
                $body['webhook_url'] = $webhookUrl;
            }

            $url = 'https://hubapi.plug.com/auth/apps/access-tokens';
            $headers = [
                'PublicAppKey' => MPSetup::getHubAppPublicAppKey(),
                'Content-Type' => 'application/json'
            ];

            $result = Request::post(
                $url,
                $headers,
                json_encode($body)
            );

            if ($result->code === 201) {
                $this->executeCommandFromPost($result->body);

                //if its ok
                $installToken->setUsed(true);
                $tokenRepo->save($installToken);
            }
        }
    }

    public function getHubStatus()
    {
        $moduleConfig = MPSetup::getModuleConfiguration();

        return $moduleConfig->isHubEnabled() ? 'enabled' : 'disabled';
    }

    public function executeCommandFromPost($body)
    {
        $commandFactory = new HubCommandFactory();
        $command = $commandFactory->createFromStdClass($body);
        $command->execute();
    }
}
