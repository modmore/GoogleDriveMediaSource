<?php
namespace modmore\GoogleDriveMediaSource\Model;

use Google\Auth\OAuth2;
use Google\Client;
use Google\Service\Drive;
use League\Flysystem\FilesystemException;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\Visibility;
use modmore\GoogleDriveMediaSource\Adapter\Directory;
use modmore\GoogleDriveMediaSource\Adapter\DriveAdapter;
use modmore\GoogleDriveMediaSource\Adapter\File;
use modmore\RevolutionCache\Pool;
use MODX\Revolution\modX;
use MODX\Revolution\Sources\modMediaSource;
use xPDO\xPDO;

global $logger;
global $modx;

$logger = function($msg) use ($modx) {
    $modx->log(1, $msg);
};

/**
 * Class GoogleDriveMediaSource
 *
 *
 * @package modmore\GoogleDriveMediaSource\Model
 * @property DriveAdapter $adapter
 */
class GoogleDriveMediaSource extends modMediaSource
{
    /**
     * @var array|mixed|\xPDO\Om\xPDOObject|null
     */
    private static array $cacheOptions = [
        xPDO::OPT_CACHE_KEY => 'googledrive',
    ];

    /**
     * @var ?Client
     */
    private ?Client $client = null;

    private string $root = '';

    public function initialize()
    {
        parent::initialize();

        $properties = $this->getProperties();

        $client = $this->client();
        $drive = new Drive($client);

        $cache = new Pool($this->xpdo, self::$cacheOptions[xPDO::OPT_CACHE_KEY]);
        $adapter = new DriveAdapter($drive, [
            'cache' => $cache,
            'root' => $properties['root']['value'] ?? '',
            'maxItemsPerLevel' => $properties['maxItemsPerLevel']['value'] ?? 250,
        ]);
        $this->loadFlySystem($adapter);
        return true;
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

    public function getBases($path = ''): array
    {
        return [
            'path' => $this->root,
            'pathIsRelative' => false,
            'pathAbsolute' => $this->root,
            'pathAbsoluteWithPath' => $path,
            'pathRelative' => $path,
            'urlIsRelative' => false,
            'url' => $this->root,
            'urlAbsolute' => $this->root,
            'urlAbsoluteWithPath' => $path,
            'urlRelative' => $path,
        ];
    }

    /**
     * Get the default properties for the filesystem media source type.
     *
     * @return array
     */
    public function getDefaultProperties(): array
    {

        $this->xpdo->lexicon->load('googledrivemediasource:default');

        return [
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
            'root' => [
                'name' => 'root',
                'desc' => $this->xpdo->lexicon('googledrivemediasource.root_desc'),
                'type' => 'list',
                'options' => [],
                'value' => '',
            ],
            'maxItemsPerLevel' => [
                'name' => 'maxItemsPerLevel',
                'desc' => $this->xpdo->lexicon('googledrivemediasource.maxItemsPerLevel_desc'),
                'type' => 'numberfield',
                'options' => [],
                'value' => 250,
            ],
        ];
    }


    public function prepareProperties(array $properties = []): array
    {
        $oAuth = $this->oauth2($properties);

        // Look for auth codes passed in
        if (isset($_REQUEST['code'])) {
            $this->checkAuthorizationCode($oAuth, $_REQUEST['code']);
        }

        if (!$oAuth->getClientId() || !$oAuth->getClientSecret()) {
            unset($properties['refreshToken']);

            return parent::prepareProperties($properties);
        }

        if ($oAuth->getRefreshToken()) {
            if (isset($_GET['a']) && $_GET['a'] === 'source/update') {
                $properties['root']['options'] = [];

                $properties['root']['options'][] = [
                    'name' => '- root -',
                    'value' => 'root',
                ];

                $client = $this->client($oAuth);
                $drive = new Drive($client);
                $adapter = new DriveAdapter($drive);

                try {
                    $folders = $adapter->listContents('', false);

                    foreach ($folders as $folder) {
                        if (!$folder->isDir()) {
                            continue;
                        }
                        $properties['root']['options'][] = [
                            'name' => $folder->getName(),
                            'value' => $folder->path(),
                        ];
                    }

                } catch (UnableToRetrieveMetadata $e) {
                    $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, 'Received error loading root options for Google Drive Media Source: ' . $e->getMessage());
                    return parent::prepareProperties($properties);
                }


//            $drives = $adapter->getService()->teamdrives->listTeamdrives()->getTeamDrives();
//            foreach ($drives as $drive) {
//                $properties['root']['options'][] = [
//                    'name' => '[Team Drive] ' . $drive->name,
//                    'value' => 'teamdrive/' . $drive->id,
//                ];
////            }
            }

            return parent::prepareProperties($properties);
        }
        unset($properties['root']);

        $properties['refreshToken']['desc'] = '<a href="' . $oAuth->buildFullAuthorizationUri() . '" class="x-btn primary-button">Authorize your Google Account</a>';

        return parent::prepareProperties($properties);
    }

    private function oauth2(array $properties): OAuth2
    {
        $oAuth = new OAuth2([
            'scope' => 'https://www.googleapis.com/auth/drive',
            'tokenCredentialUri' => 'https://oauth2.googleapis.com/token',
            'authorizationUri' => 'https://accounts.google.com/o/oauth2/auth',
//            'redirectUri' => 'https://' . $this->xpdo->getOption('http_host') . $this->xpdo->getOption('manager_url') . '?a=source/update&id=' . $this->get('id'),
            'redirectUri' => 'https://mm-commerce.eu.ngrok.io' . $this->xpdo->getOption('manager_url') . '?a=source/update&id=' . $this->get('id'),
            'clientId' => $properties['clientId']['value'] ?? '',
            'clientSecret' => $properties['clientSecret']['value'] ?? '',
            'refreshToken' => $properties['refreshToken']['value'] ?? '',
        ]);

        if ($tokens = $this->xpdo->getCacheManager()->get('access_token_' . $this->get('id'), self::$cacheOptions)) {
            $oAuth->updateToken($tokens);
        }

        $oAuth->setRefreshToken($properties['refreshToken']['value'] ?? '');

        return $oAuth;
    }

    private function client(?OAuth2 $oauth = null): Client
    {
        if ($this->client) {
            return $this->client;
        }

        $oauth = $oauth ?? $this->oauth2($this->getProperties());
        $this->client = new Client([
            'client_id' => $oauth->getClientId(),
            'client_secret' => $oauth->getClientSecret(),
        ]);
        if ($tokens = $this->xpdo->getCacheManager()->get('access_token_' . $this->get('id'), self::$cacheOptions)) {
            $this->client->setAccessToken($tokens);
        }
        else {
            $tokens = $this->client->fetchAccessTokenWithRefreshToken($oauth->getRefreshToken());
//            var_dump($tokens);exit();

            $this->xpdo->getCacheManager()->set('access_token_' . $this->get('id'), $tokens, $tokens['expires_in'] - 60, self::$cacheOptions);
        }

        return $this->client;
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
                $this->xpdo->getCacheManager()->set('access_token_' . $this->get('id'), $tokens, $tokens['expires_in'], self::$cacheOptions);
            }

        } catch (\Exception $e) {
            $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, '[GoogleDriveMediaSource] Received oAuth error when verifying token: ' . $e->getMessage());
        }
    }

    public function getContainerList($path)
    {
        $properties = $this->getPropertyListWithDefaults();
        $path = $this->postfixSlash($path);
        if ($path == DIRECTORY_SEPARATOR || $path == '\\') {
            $path = '';
        }

        $bases = $this->getBases($path);
        $imageExtensions = explode(',', $properties['imageExtensions']);
        $skipFiles = $this->getSkipFilesArray($properties);
        $allowedExtensions = $this->getAllowedExtensionsArray($properties);

        $directories = $dirNames = $files = $fileNames = [];

        if (!empty($path)) {

            // Ensure the provided path can be read.
            try {
                $dir = $this->adapter->get($path);
            } catch (FilesystemException | UnableToRetrieveMetadata $e) {
                $this->addError('path', $e->getMessage());
                $this->xpdo->log(modX::LOG_LEVEL_ERROR, $e->getMessage());
                return [];
            }

            if (!($dir instanceof Directory)) {
                $this->addError('path', $this->xpdo->lexicon('file_folder_err_invalid'));
                return [];
            }

        }

        try {
            $re = '#^(.*?/|)(' . implode('|', array_map('preg_quote', $skipFiles)) . ')/?$#';
            $contents = $this->filesystem->listContents($path);
            $contents->filter(function(StorageAttributes $attributes) use ($re) {
                return !preg_match($re, $attributes->path());
            })
                ->filter(function(StorageAttributes $attributes) use ($properties) {
                    if ($attributes instanceof Directory) {
                        return $this->hasPermission('directory_list');
                    }
                    if ($attributes instanceof File) {
                        return $this->hasPermission('file_list') && !$properties['hideFiles'];
                    }

                    return false;
                });

            /** @var File|Directory $object */
            foreach ($contents as $object) {
                $path = $object->path();
                $id = $object->getId();
                $name = $object->getName();

                $this->xpdo->log(1, print_r($object->toCacheArray(), true));

                if ($object instanceof Directory) {
                    $cls = $this->getExtJSDirClasses();
                    $dirNames[] = strtoupper($name);
                    $visibility = $this->visibility_dirs ? $this->getVisibility($object['path']) : Visibility::PRIVATE;
                    $directories[$id] = [
                        'id' => $id,
                        'sid' => $this->get('id'),
                        'text' => $name,
                        'cls' => implode(' ', $cls),
                        'iconCls' => 'icon icon-folder',
                        'type' => 'dir',
                        'leaf' => false,
                        'path' => $path,
                        'pathRelative' => $path,
                        'menu' => [],
                    ];
                    if ($this->visibility_dirs && $visibility) {
                        $directories[$id]['visibility'] = $visibility;
                    }
                    $directories[$id]['menu'] = [
                        'items' => $this->getListDirContextMenu(), // @todo
                    ];

                }
                elseif ($object instanceof File) {
                    // @TODO review/refactor extension and mime_type would be better for filesystems that
                    // may not always have an extension on it. For example would be S3 and you have an HTML file
                    // but the name is just myPage - $this->filesystem->getMimetype($object['path']);
                    $ext = pathinfo($name, PATHINFO_EXTENSION);
                    $ext = $properties['use_multibyte']
                        ? mb_strtolower($ext, $properties['modx_charset'])
                        : strtolower($ext);
                    if (!empty($allowedExtensions) && !in_array($ext, $allowedExtensions)) {
                        continue;
                    }
                    $fileNames[] = strtoupper($id);
                    $files[$id] = $this->_buildFileList($object, $ext, $imageExtensions, $bases, $properties);
                }
            }

            $ls = [];
            // now sort files/directories
            array_multisort($dirNames, SORT_ASC, SORT_STRING, $directories);
            foreach ($directories as $dir) {
                $ls[] = $dir;
            }

//            array_multisort($fileNames, SORT_ASC, SORT_STRING, $files);
            foreach ($files as $file) {
                $ls[] = $file;
            }

            return $ls;

        } catch (FilesystemException $e) {
            $this->addError('path', $e->getMessage());
            $this->xpdo->log(modX::LOG_LEVEL_ERROR, $e->getMessage());
            return [];
        }
    }

    protected function _buildFileList(File $file, $ext, $imageExtensions, $bases, $properties)
    {
        $path = $file->path();
        $file_name = basename($path);

        $editAction = $this->getEditActionId();
        $canSave = $this->checkPolicy('save');
        $canRemove = $this->checkPolicy('remove');
        $driveCapabilities = $file->file->getCapabilities();
        $id = rawurlencode(htmlspecialchars_decode($path));

        $cls = [];

        $fullPath = $path;
        if (!empty($bases['pathAbsolute'])) {
            $fullPath = $bases['pathAbsolute'] . ltrim($path, DIRECTORY_SEPARATOR);
        }

//        if (!empty($properties['currentFile']) && rawurldecode($properties['currentFile']) == $fullPath . $path && $properties['currentAction'] == $editAction) {
//            $cls[] = 'active-node';
//        }
        if ($driveCapabilities->canDelete && $canRemove && $this->hasPermission('file_remove')) {
            $cls[] = 'premove';
        }
        if ($driveCapabilities->canEdit && $canSave && $this->hasPermission('file_update')) {
            $cls[] = 'pupdate';
        }
        $page = null;
        if (!$this->isFileBinary($path)) {
            $page = !empty($editAction)
                ? '?a=' . $editAction . '&file=' . $id . '&wctx=' . $this->ctx->get('key') . '&source=' . $this->get('id')
                : null;
        }
        $file_list = [
            'id' => $id,
            'sid' => $this->get('id'),
            'text' => $file->getName(),
            'cls' => implode(' ', $cls),
            'iconCls' => 'icon icon-file icon-' . $ext,
            'type' => 'file',
            'leaf' => true,
            'page' => $page,
            'path' => $path,
            'pathRelative' => $path,
            'directory' => $file->path(),
            'url' => $bases['url'] . $path,
            'urlExternal' => $this->getObjectUrl($path),
            'urlAbsolute' => $bases['urlAbsoluteWithPath'] . ltrim($file_name, DIRECTORY_SEPARATOR),
            'file' => rawurlencode($fullPath . $path),
            'visibility' => $file->visibility(),
        ];

        $file_list['menu'] = [
            'items' => $this->getListFileContextMenu($path, !empty($page), $file_list),
        ];

        // trough tree config we can request a tree without image-preview tooltips, don't do any work if not necessary
        if (!$properties['hideTooltips']) {
            $file_list['qtip'] = '';
            if ($this->isFileImage($path, $imageExtensions)) {
                $imageWidth = $this->ctx->getOption('filemanager_image_width', 400);
                $imageHeight = $this->ctx->getOption('filemanager_image_height', 300);
                $preview_image = $this->buildManagerImagePreview($path, $ext, $imageWidth, $imageHeight, $bases, $properties);
//                $file_list['qtip'] = '<img src="' . $preview_image['src'] . '" width="' . $preview_image['width'] . '" height="' . $preview_image['height'] . '" alt="' . $path . '" />';
            }
        }

        return $file_list;
    }


    protected function getImageDimensions($path, $ext): bool|array
    {
        try {
            $obj = $this->adapter->get($path);
            if (!$obj->isFile()) {
                return false;
            }

            $meta = $obj->file->getImageMediaMetadata();
            if ($meta && $meta->width && $meta->height) {
                return [
                    'width' => $meta->width,
                    'height' => $meta->height,
                ];
            }
        } catch (UnableToRetrieveMetadata $e) {
        }
        return false;
    }

    protected function buildManagerImagePreview($path, $ext, $width, $height, $bases, $properties = [])
    {
        $file = $this->adapter->get($path);

        $imageQuery = http_build_query([
            'src' => rawurlencode($path),
            'w' => 400,
//            'h' => $imageQueryHeight,
            'HTTP_MODAUTH' => $this->xpdo->user->getUserToken($this->xpdo->context->get('key')),
            'f' => $this->getOption('thumbnailType', $properties, 'png'),
            'q' => $this->getOption('thumbnailQuality', $properties, 90),
            'wctx' => $this->ctx->get('key'),
            'source' => $this->get('id'),
            't' => $timestamp,
            'ar' => 'x'
        ]);
        $image = $this->ctx->getOption('connectors_url', MODX_CONNECTORS_URL) . 'system/phpthumb.php?' . $imageQuery;

        return [
            'src' => $image,
            'width' => $width,
            'height' => $height,
        ];
    }
}
