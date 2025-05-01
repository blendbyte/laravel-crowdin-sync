<?php

namespace Blendbyte\LaravelCrowdinSync;

use CrowdinApiClient\Crowdin;
use CrowdinApiClient\Model\File;
use CrowdinApiClient\Model\SourceString;
use CrowdinApiClient\ModelCollection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

class LaravelCrowdinSync
{
    public int $project_id_files;

    public int $project_id_content;

    public string $api_key;

    public Crowdin $client;

    public ModelCollection $directories;

    public ModelCollection $files;

    public string $files_source_language_id;

    public array $files_target_language_ids;

    public int $content_branch_id;

    public string $content_source_language_id;

    public array $content_target_language_ids;

    public static function make(): LaravelCrowdinSync
    {
        return new self;
    }

    public function __construct(?int $project_id_files = null, ?int $project_id_content = null, ?string $api_key = null)
    {
        $this->project_id_files = $project_id_files ?? config('crowdin-sync.project_id_files');
        $this->project_id_content = $project_id_content ?? config('crowdin-sync.project_id_content');
        $this->api_key = $api_key ?? config('crowdin-sync.api_key');
        $this->content_branch_id = config('crowdin-sync.content_branch_id', -1);
        $this->client = new Crowdin(['access_token' => $this->api_key]);
    }

    // FILES
    public function syncFiles(string $source_path, string $crowdin_path, array $include_file_names = []): self
    {
        $this->uploadFiles($source_path, $crowdin_path, $include_file_names);
        $this->downloadFiles($source_path, $crowdin_path, $include_file_names);

        return $this;
    }

    public function uploadFiles(string $source_path, string $crowdin_path, array $include_file_names = []): self
    {
        $this->prepareFileHandling();
        $sources = $this->prepareFiletree($source_path, $crowdin_path, $include_file_names);

        foreach ($sources as $source) {
            $path = $this->splitPath($source['target']);
            $directory_id = $this->findOrCreateDirectory($path['path']);
            $file_id = $this->findFileInDirectory($path['file'], $directory_id)?->getId();

            if (config('crowdin-sync.debug')) {
                echo "Uploading file: {$path['path']}/{$path['file']}\n";
            }

            // upload
            $storage_id = $this->client->apiRequest(
                method: 'post',
                path: 'storages',
                options: [
                    'headers' => [
                        'Crowdin-API-FileName' => $path['file'],
                        'Content-Type' => 'application/octet-stream',
                    ],
                    'body' => $source['content'],
                ]
            )['data']['id'];

            if (! $file_id) {
                $this->client->file
                    ->create($this->project_id_files, [
                        'name' => $path['file'],
                        'directoryId' => $directory_id,
                        'storageId' => $storage_id,
                    ])
                    ->getId();
            } else {
                $this->client->file
                    ->update($this->project_id_files, $file_id, [
                        'updateOption' => config('crowdin-sync.file_update_options'),
                        'name' => $path['file'],
                        'storageId' => $storage_id,
                    ])
                    ->getId();
            }
        }

        unset($sources);

        return $this;
    }

    public function downloadFiles(string $source_path, string $crowdin_path, array $include_file_names = []): self
    {
        $this->prepareFileHandling();
        $sources = $this->prepareFiletree($source_path, $crowdin_path, $include_file_names);

        foreach ($sources as $source) {
            $path = $this->splitPath($source['target']);
            $directory_id = $this->findOrCreateDirectory($path['path']);
            $file_id = $this->findFileInDirectory($path['file'], $directory_id)?->getId();

            foreach ($this->files_target_language_ids as $language) {
                if (config('crowdin-sync.debug')) {
                    echo "Downloading file: {$path['path']}/{$path['file']} in $language\n";
                }

                $download = $this->client->translation
                    ->buildProjectFileTranslation($this->project_id_files, $file_id, [
                        'targetLanguageId' => $language,
                        'skipUntranslatedStrings' => true,
                        'exportApprovedOnly' => config('crowdin-sync.file_export_approved_only'),
                    ])
                    ?->getUrl();

                $content = Http::get($download)->body();

                // reset placeholders
                $content = preg_replace('/\{{((\w){1,})}}/U', ':$1', $content);

                // remove empty translations from file
                $back = [];
                foreach (explode("\n", $content) as $n) {
                    if (str_contains($n, '=> \'\'') || trim($n) === '') {
                        continue;
                    }
                    $back[] = $n;
                }
                $content = implode("\n", $back);

                // Make sure directory exists
                $language_folder = explode('-', $language, 2)[0];
                if (! is_dir($source['source_path'].$language_folder)) {
                    mkdir($source['source_path'].$language_folder);
                }

                file_put_contents($source['source_path'].$language_folder.'/'.$source['source_file'], $content);
            }
        }

        unset($sources);

        return $this;
    }

    private function splitPath(string $path): array
    {
        $spl = explode('/', $path);

        return [
            'file' => array_pop($spl),
            'path' => implode('/', $spl),
        ];
    }

    private function findFileInDirectory(string $file_path, int $directory_id): ?File
    {
        foreach ($this->files as $file) {
            if ($file->getDirectoryId() === $directory_id && $file->getName() === $file_path) {
                return $file;
            }
        }

        return null;
    }

    private function findOrCreateDirectory(string $path): int
    {
        foreach ($this->directories as $dir) {
            if ($dir->getName() === $path) {
                return $dir->getId();
            }
        }

        $directory = $this->client->directory->create($this->project_id_files, ['name' => $path])?->getId();

        // refresh list
        $this->directories = $this->client->directory->list($this->project_id_files);

        return $directory;
    }

    private function prepareFiletree(string $source_path, string $crowdin_path, array $include_file_names = []): array
    {
        $filetree = [];
        $fs_path = base_path(rtrim($source_path, '/').'/'.$this->files_source_language_id.'/');
        foreach (scandir($fs_path) as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            // only include certain files
            if (count($include_file_names) > 0 && ! in_array($file, $include_file_names, true)) {
                continue;
            }

            // get content and replace placeholders with crowdin type
            $content = file_get_contents($fs_path.$file);
            $content = preg_replace('/\:((\w){1,})\b/U', '{{$1}}', $content);

            $filetree[] = [
                'source_path' => base_path(rtrim($source_path, '/').'/'),
                'source_file' => $file,
                'target' => rtrim($crowdin_path, '/').'/'.$file,
                'content' => $content,
            ];
        }

        return $filetree;
    }

    private function prepareFileHandling(): void
    {
        if (! isset($this->files_source_language_id, $this->files_target_language_ids)) {
            $project = $this->client->project->get($this->project_id_files);
            $this->files_source_language_id = $project?->getSourceLanguageId();
            $this->files_target_language_ids = $project?->getTargetLanguageIds();
        }

        if (! isset($this->directories)) {
            $this->directories = $this->client->directory->list($this->project_id_files);
        }

        if (! isset($this->files)) {
            $this->files = $this->client->file->list($this->project_id_files);
        }
    }
    // -- FILES

    // CONTENT
    public function syncContent(string $name, string $eloquent_model, ?array $fields = null, ?callable $context = null): self
    {
        $this->uploadContent($name, $eloquent_model, $fields, $context);
        $this->downloadContent($name, $eloquent_model, $fields);

        return $this;
    }

    public function uploadContent(string $name, string $eloquent_model, ?array $fields = null, ?callable $context = null): self
    {
        $this->prepareContentHandling($eloquent_model);

        /** @var Model $eloquent_model */
        $eloquent_model::chunk(100, function (Collection $rows) use ($name, $fields, $context) {
            foreach ($rows as $row) {
                $context = (is_callable($context) ? $context($row) : '') ?? '';

                foreach ($fields as $field_key => $field) {
                    if (is_array($field)) {
                        $field_modifier = $field['upload'];
                        $field = $field_key;
                    } else {
                        $field_modifier = null;
                    }

                    $identifier = $name.'.'.$field.'['.$row->{$row->getKeyName()}.']';
                    $source_content = $field_modifier ? $field_modifier($row->$field, $this->content_source_language_id) : $row->getTranslation($field, $this->content_source_language_id);
                    if (! $source_content) {
                        continue;
                    }

                    $find_sourcestring = $this->client->sourceString
                        ->list($this->project_id_content, [
                            'filter' => $identifier,
                            'scope' => 'identifier',
                        ]);
                    if (count($find_sourcestring) > 0) {
                        foreach ($find_sourcestring as $string) {
                            if ($string->getText() === $source_content && $string->getContext() === $context) {
                                // Didn't change
                                if (config('crowdin-sync.debug')) {
                                    echo "No update required for $identifier (unchanged)\n";
                                }

                                continue;
                            }

                            if (config('crowdin-sync.debug')) {
                                echo "Updating string for $identifier (".mb_strlen($source_content)." characters)\n";
                            }

                            $string->setText($source_content);

                            if ($context) {
                                $string->setContext($context);
                            }

                            $this->client->sourceString->update($string);
                        }
                    } else {
                        if (config('crowdin-sync.debug')) {
                            echo "Adding string for $identifier (".mb_strlen($source_content)." characters)\n";
                        }

                        $string = new SourceString([
                            'branchId' => $this->content_branch_id,
                            'identifier' => $identifier,
                            'text' => $source_content,
                        ]);
                        if ($context) {
                            $string->setContext($context);
                        }
                        $this->client->sourceString->create($this->project_id_content, $string->getData());
                    }
                }
            }
        });

        return $this;
    }

    public function downloadContent(string $name, string $eloquent_model, ?array $fields = null, ?callable $context = null): self
    {
        $this->prepareContentHandling($eloquent_model);

        /** @var Model $eloquent_model */
        $eloquent_model::chunk(100, function (Collection $rows) use ($name, $fields) {
            foreach ($rows as $row) {
                foreach ($fields as $field_key => $field) {
                    if (is_array($field)) {
                        $field_modifier = $field['download'];
                        $field = $field_key;
                    } else {
                        $field_modifier = null;
                    }

                    $identifier = $name.'.'.$field.'['.$row->{$row->getKeyName()}.']';

                    // Find string
                    $find_sourcestring = $this->client->sourceString
                        ->list($this->project_id_content, [
                            'filter' => $identifier,
                            'scope' => 'identifier',
                        ]);
                    if (count($find_sourcestring) !== 1) {
                        continue;
                    }
                    $sourcestring_id = $find_sourcestring[0]->getId();

                    // Get translations
                    foreach ($this->content_target_language_ids as $language) {
                        if (config('crowdin-sync.content_approved_only')) {
                            $approvals = $this->client->stringTranslation->listApprovals($this->project_id_content, [
                                'stringId' => $sourcestring_id,
                                'languageId' => $language,
                            ]);
                            if (count($approvals) === 0) {
                                if (config('crowdin-sync.debug')) {
                                    echo "Skipping $language translation for $identifier (no approved translation)\n";
                                }

                                continue;
                            }

                            $translation = $this->client->stringTranslation->get($this->project_id_content, $approvals[0]->getTranslationId());
                        } else {
                            $translations = $this->client->stringTranslation->list($this->project_id_content, [
                                'stringId' => $sourcestring_id,
                                'languageId' => $language,
                                'orderBy' => 'rating',
                            ]);
                            if (count($translations) === 0) {
                                if (config('crowdin-sync.debug')) {
                                    echo "Skipping $language translation for $identifier (no translation)\n";
                                }

                                continue;
                            }
                            $translation = $translations[0];
                        }

                        if (! $translation) {
                            if (config('crowdin-sync.debug')) {
                                echo "Skipping $language translation for $identifier (invalid)\n";
                            }

                            continue;
                        }

                        $target_content = $translation->getText();

                        if (config('crowdin-sync.debug')) {
                            echo "Updating $language translation for $identifier (".mb_strlen(is_array($target_content) ? json_encode($target_content) : $target_content)." characters)\n";
                        }

                        if ($field_modifier) {
                            $row->$field = $field_modifier($target_content, $language, $row->$field);
                        } else {
                            $row->setTranslation($field, $language, $target_content);
                        }
                    }
                }

                $row->save();
            }
        });

        return $this;
    }

    private function prepareContentHandling(string $eloquent_model): void
    {
        if (! isset($this->content_source_language_id, $this->content_target_language_ids)) {
            $project = $this->client->project->get($this->project_id_content);
            $this->content_source_language_id = $project?->getSourceLanguageId();
            $this->content_target_language_ids = $project?->getTargetLanguageIds();
        }
    }
    // -- CONTENT
}
