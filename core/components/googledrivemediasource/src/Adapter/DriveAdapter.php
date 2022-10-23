<?php

namespace modmore\GoogleDriveMediaSource\Adapter;

use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToRetrieveMetadata;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

class DriveAdapter implements FilesystemAdapter
{
    protected const DIRECTORY_MIME = 'application/vnd.google-apps.folder';
    protected const LIST_FIELDS = 'files(id,mimeType,createdTime,modifiedTime,name,parents,permissions,size,webContentLink,webViewLink,iconLink,contentHints,imageMediaMetadata,capabilities,exportLinks,resourceKey),nextPageToken';
    protected const GET_FIELDS = 'id,mimeType,createdTime,modifiedTime,name,parents,permissions,size,webContentLink,webViewLink,iconLink,contentHints,imageMediaMetadata,capabilities,exportLinks,resourceKey';

    private Drive $drive;
    private ?CacheItemPoolInterface $cache = null;
    private string $root;
    private int $maxItemsPerLevel = 250;

    public function __construct(Drive $drive, array $config = [])
    {
        $this->drive = $drive;
        $this->root = $config['root'] ?? 'root';


        if (isset($config['cache']) && $config['cache'] instanceof CacheItemPoolInterface) {
            $this->cache = $config['cache'];
        }
        if (isset($config['maxItemsPerLevel']) && is_numeric($config['maxItemsPerLevel']) && abs($config['maxItemsPerLevel']) < 1000) {
            $this->maxItemsPerLevel = (int)abs($config['maxItemsPerLevel']);
        }
    }

    public function get(string $path): File|Directory
    {
        $segments = explode('/', trim($path, '/'));
        $fileId = end($segments);

        $params = [
            'fields' => self::GET_FIELDS,
        ];

        $item = $this->cache ? $this->cache->getItem($fileId) : false;
        if ($item && $item->isHit()) {
            return CacheableTrait::fromCacheArray($item->get());
        }

        try {
            $file = $this->drive->files->get($fileId, $params);
        } catch (\Exception $e) {
            throw new UnableToRetrieveMetadata($e->getMessage());
        }
        
        if ($this->cache && $item) {
            $obj = $this->convertToFile($file);
            $item->set($obj->toCacheArray());
            $item->expiresAfter(new \DateInterval('PT5M'));
            $this->cache->save($item);
        }
        
        return $this->convertToFile($file);
    }

    /**
     * @param string $path
     * @param string $query
     * @return array|Directory[]|File[]
     * @throws InvalidArgumentException
     * @throws UnableToRetrieveMetadata
     */
    public function list(string $path, string $query = ''): array
    {
        global $logger;
        $segments = explode('/', $path);
        $parent = end($segments) ?: 'root';
        if ($parent === 'root' || empty($parent)) {
            $parent = $this->root;
        }

        $logger($parent);


        $cacheKey = '-list-' . $this->root . '-' . str_replace('/', '_', $path);
        if (empty($query)) {
            $cacheKey .= sha1($query);
        }

        $item = $this->cache ? $this->cache->getItem($cacheKey) : false;
        if ($item && $item->isHit()) {
            $data = $item->get();

            $results = [];
            foreach ($data as $item) {
                $results[] = CacheableTrait::fromCacheArray($item);
            }
            return $results;
        }

        $results = $this->fetchList($parent, $query);
        if ($item) {
            $cache = [];
            foreach ($results as $obj) {
                $objArray = $obj->toCacheArray();
                $cache[] = $objArray;

                // Pre-fill individual cache too
                $indivItem = $this->cache->getItem($obj->getId());
                $indivItem->set($objArray);
                $this->cache->saveDeferred($indivItem);
            }

            $this->cache->commit();

            $item->set($cache);
            $item->expiresAfter(new \DateInterval('PT5M'));
            $this->cache->save($item);
        }

        return $results;
    }

    /**
     * @param string $parent
     * @param string $query
     * @return File[]|Directory[]
     * @throws UnableToRetrieveMetadata
     */
    private function fetchList(string $parent = 'root', string $query = ''): array
    {
        $results = [];

        $q = "trashed = false and '{$parent}' in parents";
        if (!empty($query)) {
            $q .= ' and (' . $query . ')';
        }

        $pageToken = null;
        do {
            $params = [
                'q' => $q,
                'pageSize' => 100,
                'orderBy' => 'folder, name',
                'spaces' => 'drive',
                'pageToken' => $pageToken,
                'fields' => self::LIST_FIELDS,
            ];

            try {
                $response = $this->drive->files->listFiles($params);
            } catch (\Exception $e) {
                throw new UnableToRetrieveMetadata($e->getMessage());
            }

            foreach ($response->getFiles() as $file) {
                $results[] = $this->convertToFile($file, $parent);
            }

            // Somewhere past ~ 500 items it consistently runs out of memory
//            $pageToken = null;
            $pageToken = $response->getNextPageToken();
        } while (!empty($pageToken) && count($results) < $this->maxItemsPerLevel);
        return $results;
    }

    public function fileExists(string $path): bool
    {
        // TODO: Implement fileExists() method.
        return $this->get($path) instanceof StorageAttributes;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        // TODO: Implement write() method.
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        // TODO: Implement writeStream() method.
    }

    public function read(string $path): string
    {
        $file = $this->get($path);
        if (!($file instanceof File)) {
            throw new UnableToRetrieveMetadata('Does not exist or is not a file');
        }

        $response = $this->drive->files->get($file->getId(), [
            'alt' => 'media',
        ]);

        if ($response) {
            return (string)$response->getBody();
        }
        return 'empty';
    }

    public function readStream(string $path)
    {
        // TODO: Implement readStream() method.
    }

    public function delete(string $path): void
    {
        // TODO: Implement delete() method.
    }

    public function deleteDirectory(string $path): void
    {
        // TODO: Implement deleteDirectory() method.
    }

    public function createDirectory(string $path, Config $config): void
    {
        // TODO: Implement createDirectory() method.
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // TODO: Implement setVisibility() method.
    }

    public function visibility(string $path): File
    {
        $file = $this->get($path);
        if (!($file instanceof File)) {
            throw new UnableToRetrieveMetadata('File does not exist or is a directory');
        }
        return $file;
    }

    public function mimeType(string $path): FileAttributes
    {
        $file = $this->get($path);
        if ($file instanceof Directory) {
            return new FileAttributes($path, null, null, null, 'directory');
        }

        if (!($file instanceof File)) {
            throw new UnableToRetrieveMetadata('File does not exist');
        }
        return new FileAttributes($path, null, null, null, $file->mimeType());
    }

    public function lastModified(string $path): File
    {
        $file = $this->get($path);
        if (!($file instanceof File)) {
            throw new UnableToRetrieveMetadata('File does not exist or is a directory');
        }
        return $file;
    }

    public function fileSize(string $path): File
    {
        $file = $this->get($path);
        if (!($file instanceof File)) {
            throw new UnableToRetrieveMetadata('File does not exist or is a directory');
        }
        return $file;
    }

    /**
     * @param string $path
     * @param bool $deep
     * @return File[]|Directory[]|iterable
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $list = $this->list($path);
        foreach ($list as $item) {
            yield $item;
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        // TODO: Implement move() method.
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        // TODO: Implement copy() method.
    }

    /**
     * @param DriveFile $file
     * @param string $parent
     * @return File|Directory
     */
    private function convertToFile(DriveFile $file, string $parent = 'root'): File|Directory
    {
        $path = ($parent !== 'root' ? $parent . '/' : '') . $file->getId();
        global $logger;
        $logger($path . ' = ' . $file->getMimeType() . ' or ' . $file->getKind());

        // In a search, directories appear with a mime type
        if ($file->getMimeType() === self::DIRECTORY_MIME) {
            return new Directory($file, $path);
        }
        // In a simple get by ID, they appear just with a different kind
        // and lack an ID
        if ($file->getKind() === 'drive#fileList') {
            $logger('setting id to ' . $parent);
            $file->id = $parent;
            return new Directory($file, $path);
        }

        return new File($file, $path);
    }

}