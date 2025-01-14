<?php

namespace Blendbyte\LaravelCrowdinSync;

use CrowdinApiClient\Model\File;
use CrowdinApiClient\ModelCollection;
use Illuminate\Support\Facades\Http;

class LaravelCrowdinSync
{
    public int $project_id_files;

    public int $project_id_content;

    public string $api_key;

    public \CrowdinApiClient\Crowdin $client;

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
        $this->client = new \CrowdinApiClient\Crowdin(['access_token' => $this->api_key]);
    }

    public function syncFiles(array $folders): self
    {
        $this->uploadFiles($folders);
        $this->downloadFiles($folders);

        return $this;
    }

    public function uploadFiles(array $folders): self
    {
        $this->prepareFileHandling();
        $sources = $this->prepareFiletree($folders);

        foreach ($sources as $source) {
            $path = $this->splitPath($source['target']);
            $directory_id = $this->findOrCreateDirectory($path['path']);
            $file_id = $this->findFileInDirectory($path['file'], $directory_id)?->getId();

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

    public function downloadFiles(array $folders): self
    {
        $this->prepareFileHandling();
        $sources = $this->prepareFiletree($folders);

        foreach ($sources as $source) {
            $path = $this->splitPath($source['target']);
            $directory_id = $this->findOrCreateDirectory($path['path']);
            $file_id = $this->findFileInDirectory($path['file'], $directory_id)?->getId();

            foreach ($this->files_target_language_ids as $language) {
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

                file_put_contents($source['source_path'].$language.'/'.$source['source_file'], $content);
            }
        }

        unset($sources);

        return $this;
    }

    public function syncContent(string $eloquent_model, array $fields = [], ?string $model_name = null): self
    {
        $this->uploadContent($eloquent_model, $fields, $model_name);
        $this->downloadContent($eloquent_model, $fields, $model_name);

        return $this;
    }

    public function uploadContent(string $eloquent_model, array $fields = [], ?string $model_name = null): self
    {
        $this->prepareContentHandling();
        $amodel = $this->aggregateContentModel($eloquent_model, $fields, $model_name);

        foreach ($eloquent_model::all() as $model) {
            foreach ($amodel['fields'] as $field) {
                $identifier = $amodel['name'].'.'.$field.'['.$model->{$amodel['id_field']}.']';
                $content = $model->getTranslation($field, $this->content_source_language_id);
                if (! $content || is_array($content)) {
                    continue;
                }

                $find_sourcestring = $this->client->sourceString
                    ->list($this->project_id_content, [
                        'filter' => $identifier,
                        'scope' => 'identifier',
                    ]);
                if (count($find_sourcestring) > 0) {
                    foreach ($find_sourcestring as $f) {
                        if ($f->getText() === $content) {
                            continue;
                        }
                        $f->setText($content);
                        $this->client->sourceString->update($f);
                    }
                } else {
                    $this->client->sourceString->create($this->project_id_content, [
                        'branchId' => $this->content_branch_id,
                        'identifier' => $identifier,
                        'text' => $content,
                    ]);
                }
            }
        }

        return $this;
    }

    public function downloadContent(string $eloquent_model, array $fields = [], ?string $model_name = null): self
    {
        $this->prepareContentHandling();
        $amodel = $this->aggregateContentModel($eloquent_model, $fields, $model_name);

        foreach ($eloquent_model::all() as $model) {
            foreach ($amodel['fields'] as $field) {
                $identifier = $amodel['name'].'.'.$field.'['.$model->{$amodel['id_field']}.']';

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
                    $translations = $this->client->stringTranslation
                        ->list($this->project_id_content, [
                            'stringId' => $sourcestring_id,
                            'languageId' => $language,
                            'orderBy' => 'rating',
                        ]);
                    if (count($translations) === 0) {
                        continue;
                    }
                    $translation = $translations[0]->getText();

                    $model->setTranslation($field, $language, $translation);
                }
            }

            $model->save();
        }

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

    private function prepareFiletree(array $folders): array
    {
        $filetree = [];
        foreach ($folders as $source => $target) {
            $fs_path = base_path(rtrim($source, '/').'/'.$this->files_source_language_id.'/');
            foreach (scandir($fs_path) as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $content = file_get_contents($fs_path.$file);

                // replace placeholders with crowdin type
                $content = preg_replace('/\:((\w){1,})\b/U', '{{$1}}', $content);

                $filetree[] = [
                    'source_path' => base_path(rtrim($source, '/').'/'),
                    'source_file' => $file,
                    'target' => rtrim($target, '/').'/'.$file,
                    'content' => $content,
                ];
            }
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

    private function prepareContentHandling(): void
    {
        if (! isset($this->content_source_language_id, $this->content_target_language_ids)) {
            $project = $this->client->project->get($this->project_id_content);
            $this->content_source_language_id = $project?->getSourceLanguageId();
            $this->content_target_language_ids = $project?->getTargetLanguageIds();
        }
    }

    private function aggregateContentModel(string $eloquent_model, array $fields = [], ?string $model_name = null): array
    {
        if (! in_array('Spatie\Translatable\HasTranslations', class_uses($eloquent_model), true)) {
            throw new \Exception("syncContent model needs to use Spatie\Translatable\HasTranslations");
        }

        if (count($fields) === 0) {
            $fields = (new $eloquent_model)()->getTranslatableAttributes();
        }

        return [
            'name' => $model_name ?? (new \ReflectionClass($eloquent_model))->getShortName(),
            'id_field' => (new $eloquent_model)()->getKeyName(),
            'fields' => $fields,
        ];
    }
}
