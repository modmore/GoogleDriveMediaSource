<?php


namespace modmore\GoogleDriveMediaSource\Processors;

use League\Flysystem\FilesystemException;
use modmore\GoogleDriveMediaSource\Adapter\Directory;
use modmore\GoogleDriveMediaSource\Adapter\File;
use modmore\GoogleDriveMediaSource\Model\GoogleDriveMediaSource;
use MODX\Revolution\Processors\Processor;
use MODX\Revolution\Sources\modMediaSource;
use Psr\Cache\InvalidArgumentException;
use Throwable;
use xPDO\xPDO;

class RenderFile extends Processor
{
    private GoogleDriveMediaSource $source;
    private Directory|File $file;

    public function initialize()
    {
        $sourceId = (int)$this->getProperty('source', 0);
        /** @var modMediaSource|GoogleDriveMediaSource $source */
        $source = $this->modx->getObject(modMediaSource::class, ['id' => $sourceId]);
        if (!($source instanceof GoogleDriveMediaSource) || !$source->initialize()) {
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'RenderFile request for source' . $sourceId . ' failed: source is not found or not a Google Drive type.');
            $this->error('Source unavailable.', 500);
        }

        $this->source = $source;

        $fileId = (string)$this->getProperty('id');
        try {
            $this->file = $this->source->getDriveFile($fileId);
        } catch (Throwable) {
            $this->error('File not found', 404);
        }

        return true;
    }

    public function process()
    {
        if ($this->file instanceof Directory) {
            $this->error('Invalid file.', 400);
        }

        $mime = $this->file->mimeType();
        $format = (string)$this->getProperty('format');
        if (str_starts_with($mime, 'application/vnd.google-apps')) {
            $format = $this->validatedExportFormat($mime, $format);
            header("Content-Type: $format");
        }
        else {
            header("Content-Type: $mime");
        }

        @session_write_close();
        try {
            echo $this->source->getAdapter()->read($this->file->getId(), $format);
        } catch (FilesystemException) {
            $this->error('Could not read file.', 503);
        }
        exit();
    }

    /**
     * Valid export mime formats based on the provided source mime type
     *
     * @see https://developers.google.com/drive/api/guides/ref-export-formats
     *
     * @param string $mime
     * @param string $format
     * @return string
     */
    private function validatedExportFormat(string $mime, string $format): string
    {
        switch ($mime) {
            case 'application/vnd.google-apps.document':
                if (!in_array($format, [
                    'text/html',
                    'application/zip',
                    'text/plain',
                    'application/rtf',
                    'application/vnd.oasis.opendocument.text',
                    'application/pdf',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/epub+zip',

                ], true)) {
                    $format = 'application/pdf';
                }
                break;

            case 'application/vnd.google-apps.spreadsheet':
                if (!in_array($format, [
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/x-vnd.oasis.opendocument.spreadsheet',
                    'application/pdf',
                    'text/csv',
                    'text/tab-separated-values',
                    'application/zip',
                ], true)) {
                    $format = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                }
                break;

            case 'application/vnd.google-apps.drawing':
                if (!in_array($format, [
                    'image/jpeg',
                    'image/png',
                    'image/svg+xml',
                    'application/pdf',
                ], true)) {
                    $format = 'image/jpeg';
                }
                break;

            case 'application/vnd.google-apps.presentation':
                if (!in_array($format, [
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'application/vnd.oasis.opendocument.presentation',
                    'application/pdf',
                    'text/plain',
                ], true)) {
                    $format = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
                }
                break;
        }

        return $format;
    }

    private function error(string $message, int $code = 400)
    {
        @session_write_close();
        http_response_code($code);
        echo '<h1>'. $message . '</h1>';
        exit();
    }
}

return RenderFile::class;
