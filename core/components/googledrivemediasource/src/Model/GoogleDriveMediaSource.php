<?php
namespace modmore\GoogleDriveMediaSource\Model;

use Google\Auth\OAuth2;
use Google\Client;
use xPDO\xPDO;

/**
 * Class GoogleDriveMediaSource
 *
 *
 * @package modmore\GoogleDriveMediaSource\Model
 */
class GoogleDriveMediaSource extends \MODX\Revolution\Sources\modMediaSource
{
    public function initialize()
    {
        parent::initialize();
    }

    public function getTypeName(): string
    {
        $this->xpdo->lexicon->load('googledrivemediasource:default');
        return $this->xpdo->lexicon('googledrivemediasource.type');
    }

    public function getTypeDescription(): string
    {
        $this->xpdo->lexicon->load('googledrivemediasource:default');
        return $this->xpdo->lexicon('googledrivemediasource.description');
    }


    /**
     * Get the default properties for the filesystem media source type.
     *
     * @return array
     */
    public function getDefaultProperties()
    {

        $this->xpdo->lexicon->load('googledrivemediasource:default');

        $props = [
            'clientId' => [
                'name' => 'clientId',
                'desc' => $this->xpdo->lexicon('googledrivemediasource.clientId_desc'),
                'type' => 'textfield',
                'options' => '',
                'value' => '',
//                'lexicon' => 'googledrivemediasource:default',
            ],
            'clientSecret' => [
                'name' => 'clientSecret',
                'desc' => $this->xpdo->lexicon('googledrivemediasource.clientSecret_desc'),
                'type' => 'password',
                'options' => '',
                'value' => '',
//                'lexicon' => 'googledrivemediasource:default',
            ],
            'refreshToken' => [
                'name' => 'refreshToken',
                'desc' => $this->xpdo->lexicon('googledrivemediasource.refreshToken_desc'),
                'type' => 'password',
                'options' => '',
                'value' => '',
            ],
            'otherOption' => [
                'name' => 'otherOption',
                'desc' => $this->xpdo->lexicon('googledrivemediasource.otherOptionToken_desc'),
                'type' => 'textfield',
                'options' => '',
                'value' => '',
            ],
        ];

        return $props;
    }

    public function prepareProperties(array $properties = []): array
    {
        $oAuth = $this->oauth2($properties);

        // Look for auth codes passed in
        if (isset($_REQUEST['code'])) {
            $this->checkAuthorizationCode($oAuth, $_REQUEST['code']);
        }

        if (!$oAuth->getClientId() || !$oAuth->getClientSecret()) {
            unset($properties['refreshToken'], $properties['otherOption']);

            return parent::prepareProperties($properties);
        }

        if (!$oAuth->getRefreshToken()) {
            unset($properties['otherOption']);

            $properties['refreshToken']['desc'] = '<a href="' . $oAuth->buildFullAuthorizationUri() . '" class="x-btn primary-button">Authorize your Google Account</a>';
        }

        return parent::prepareProperties($properties);
    }

    private function oauth2(array $properties): OAuth2
    {
        $oAuth = new OAuth2([
            'scope' => 'https://www.googleapis.com/auth/drive',
            'tokenCredentialUri' => 'https://oauth2.googleapis.com/token',
            'authorizationUri' => 'https://accounts.google.com/o/oauth2/auth',
            'redirectUri' => 'https://' . $this->xpdo->getOption('http_host') . $this->xpdo->getOption('manager_url') . '?a=source/update&id=' . $this->get('id'),
//            'redirectUri' => 'https://mm-commerce.eu.ngrok.io' . $this->xpdo->getOption('manager_url') . '?a=source/update&id=' . $this->get('id'),
            'clientId' => $properties['clientId']['value'] ?? '',
            'clientSecret' => $properties['clientSecret']['value'] ?? '',
            'refreshToken' => $properties['refreshToken']['value'] ?? '',
        ]);

        if ($tokens = $this->xpdo->getCacheManager()->get('access_token_' . $this->get('id'))) {
            $oAuth->updateToken($tokens);
        }

        return $oAuth;
    }

    private function client(): Client
    {
        return new Client([
            'credentials' => $this->oauth2($this->getProperties()),
        ]);
    }

    private function checkAuthorizationCode(OAuth2 $oAuth, string $code): void
    {
        $oAuth->setCode($code);

        try {
            $oAuth->setGrantType('authorization_code');
            $tokens = $oAuth->fetchAuthToken();

            if (array_key_exists('refresh_token', $tokens)) {
                $properties['refreshToken']['value'] = $tokens['refresh_token'];
                $this->setProperties([
                    'refreshToken' => $tokens['refresh_token'],
                ]);
                $this->save();
            }

            if (array_key_exists('access_token', $tokens)) {
                $this->xpdo->getCacheManager()->set('access_token_' . $this->get('id'), $tokens);
            }

        } catch (\Exception $e) {
            $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, '[GoogleDriveMediaSource] Received oAuth error when verifying token: ' . $e->getMessage());
        }
    }
}
