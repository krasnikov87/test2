<?php

namespace App\Factories\Grabber\Clients;

use App\Factories\Grabber\AbstractGitClient;
use App\Helpers\SecureHelper;
use App\Http\Repositories\CommitHistoryRepository;
use App\Http\Repositories\CommitRepository;
use App\Http\Services\CommitHistoryService;
use App\Http\Services\LogServiceInterface;
use App\Http\Services\RepositoryService;
use App\Http\Services\TenantService;
use App\Models\Account;
use App\Models\Tenant;
use App\Overrides\Clients\GitHubClient;
use Github\Client;
use Illuminate\Support\Arr;

/**
 * Class GitHub
 *
 * @package App\Factories\Grabber\Clients
 */
class GitHub extends AbstractGitClient
{
    /**
     * @var GitHubClient
     */
    protected $client;

    /**
     * @var string
     */
    protected $dateTimeFormat = \DateTime::ISO8601;

    /**
     * GitHub constructor.
     *
     * @param string $accessToken
     * @param Tenant $tenant
     * @param \GuzzleHttp\Client $httpClient
     * @param GitHubClient $client
     * @param CommitRepository $commitRepository
     * @param CommitHistoryRepository $commitHistoryRepository
     * @param TenantService $tenantService
     * @param RepositoryService $repositoryService
     * @param CommitHistoryService $commitHistoryService
     * @param LogServiceInterface $logService
     */
    public function __construct(
        string $accessToken,
        Tenant $tenant,
        \GuzzleHttp\Client $httpClient,
        GitHubClient $client,
        CommitHistoryRepository $commitHistoryRepository,
        RepositoryService $repositoryService,
        CommitHistoryService $commitHistoryService,
        LogServiceInterface $logService
    ) {
        $this->client = $client;

        parent::__construct(
            $accessToken,
            $tenant,
            $httpClient,
            $commitHistoryRepository,
            $repositoryService,
            $commitHistoryService,
            $logService
        );
    }

    /**
     * @inheritdoc
     */
    public function authenticate(): bool
    {
        $this->client->authenticate($this->accessToken, null, Client::AUTH_URL_TOKEN);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function grabRepositories(): array
    {
        $response = $this->client->user()->myRepositories();
        $output = [];

        // Transform data
        foreach ($response as $repository) {
            $output[] = [
                'id' => Arr::get($repository, 'id'),
                'name' => Arr::get($repository, 'name'),
                'fullName' => Arr::get($repository, 'full_name'),
                'url' => Arr::get($repository, 'html_url'),
                'description' => Arr::get($repository, 'description'),
                'owner' => Arr::get($repository, 'owner.login'),
                'ownerUrl' => Arr::get($repository, 'owner.html_url'),
                'createdAt' => Arr::get($repository, 'created_at'),
                'pushedAt' => Arr::get($repository, 'pushed_at'),
                'updatedAt' => Arr::get($repository, 'updated_at'),
                'location' => Account::LOCATION_GITHUB

            ];
        }

        return $output;
    }

    /**
     * @inheritdoc
     */
    public function grabBranches(array $repository): array
    {
        $branches = $this->client->repository()->branches(
            Arr::get($repository, 'owner'),
            Arr::get($repository, 'name')
        );
        $output = [];

        foreach ($branches as $branch) {
            $output[] = [
                'name' => Arr::get($branch, 'name'),
                'ref' => Arr::get($branch, 'commit.sha')
            ];
        }

        return $output;
    }

    /**
     * @inheritdoc
     */
    public function grabCommits(array $repository, string $branch): array
    {
        $output = [];

        $username = Arr::get($repository, 'owner');
        $name = Arr::get($repository, 'name');

        $response = $this->client->repository()
            ->commits()
            ->all($username, $name, ['ref' => $branch]);

        foreach ($response as $item) {
            // Skip out unissued commit
            if (!$this->isCommitIssue(Arr::get($item, 'commit.message'))) {
                continue;
            }

            $sha = Arr::get($item, 'sha');
            $commit = $this->client->repository()
                ->commits()
                ->show($username, $name, $sha);

            $patch = $this->grabCommitPatch($sha, $repository);

            $output[] = [
                'sha' => $sha,
                'comment' => Arr::get($commit, 'commit.message'),
                'url' => Arr::get($commit, 'html_url'),
                'author' => [
                    'id' => Arr::get($commit, 'committer.id'),
                    'name' => Arr::get($commit, 'commit.committer.name'),
                    'email' => Arr::get($commit, 'commit.committer.email'),
                    'username' => Arr::get($commit, 'committer.login'),
                    'avatarUrl' => Arr::get($commit, 'committer.avatar_url'),
                    'url' => Arr::get($commit, 'committer.html_url')
                ],
                'stats' => Arr::get($commit, 'stats'),
                'patch' => Arr::get($patch, 'patch'),
                'timestamp' => Arr::get($commit, 'commit.committer.date')
            ];
        }

        return $output;
    }

    /**
     * @inheritdoc
     */
    public function grabCommit(array $repository, string $sha): array
    {
        $output = [];

        $username = Arr::get($repository, 'owner');
        $name = Arr::get($repository, 'name');

        $response = $this->client->repository()
            ->commits()
            ->show($username, $name, $sha);

        // Skip out unissued commit
        if (!$this->isCommitIssue(Arr::get($response, 'commit.message'))) {
            return $output;
        }

        $sha = Arr::get($response, 'sha');
        $commit = $this->client->repository()
            ->commits()
            ->show($username, $name, $sha);

        $patch = $this->grabCommitPatch($sha, $repository);

        $output[] = [
            'sha' => $sha,
            'comment' => Arr::get($commit, 'commit.message'),
            'url' => Arr::get($commit, 'html_url'),
            'author' => [
                'id' => Arr::get($commit, 'committer.id'),
                'name' => Arr::get($commit, 'commit.committer.name'),
                'email' => Arr::get($commit, 'commit.committer.email'),
                'username' => Arr::get($commit, 'committer.login'),
                'avatarUrl' => Arr::get($commit, 'committer.avatar_url'),
                'url' => Arr::get($commit, 'committer.html_url')
            ],
            'stats' => Arr::get($commit, 'stats'),
            'patch' => Arr::get($patch, 'patch'),
            'timestamp' => Arr::get($commit, 'commit.committer.date')
        ];

        return $output;
    }


    /**
     * @inheritdoc
     */
    public function grabCommitPatch(string $sha, array $repository): array
    {
        $username = Arr::get($repository, 'owner');
        $name = Arr::get($repository, 'name');

        $response = $this->client->getHttpClient()->get(implode('/', [
            '/repos',
            rawurlencode($username),
            rawurlencode($name),
            'commits',
            rawurlencode($sha)
        ]), [
            'Accept' => 'application/vnd.github.diff'
        ]);

        $patch = $response
            ->getBody()
            ->getContents();

        return [
            'patch' => $patch
        ];
    }

    /**
     * Formats commit files data
     *
     * @param array $files
     *
     * @return array
     */
    protected function getPatchesFromFiles(array $files): array
    {
        $output = [];

        foreach ($files as $file) {
            $output[] = [
                'name' => Arr::get($file, 'filename'),
                'status' => Arr::get($file, 'status'),
                'additions' => Arr::get($file, 'additions'),
                'deletions' => Arr::get($file, 'deletions'),
                'contentsUrl' => Arr::get($file, 'contents_url'),
            ];
        }

        return $output;
    }

    /**
     * @inheritdoc
     */
    protected function createRepositoryHookRequest(array $repository): array
    {
        $repositoryId = $repository['_id'];

        $response = $this->client->repo()
            ->hooks()
            ->create($repository['owner'], $repository['name'], [
                'name' => 'web',
                'events' => ['push', "pull_request"],
                'active' => true,
                'config' => [
                    'url' => route('project.webhook', ['repository' => $repositoryId]),
                    'content_type' => 'json',
                    'secret' => SecureHelper::createRepositoryHookSecret($repositoryId),
                    'insecure_ssl' => '0'
                ]
            ]);

        return [
            'id' => Arr::get($response, 'id'),
            'name' => Arr::get($response, 'name'),
            'events' => Arr::get($response, 'events'),
            'url' => Arr::get($response, 'config.url'),
            'repositoryId' => $repositoryId
        ];
    }

    /**
     * @inheritdoc
     */
    protected function deleteRepositoryHookRequest(string $id, array $repository): array
    {
        $this->client->repo()
            ->hooks()
            ->remove($repository['owner'], $repository['name'], $id);

        return [];
    }

    /**
     * @inheritDoc
     */
    public function makePullRequest(array $repository, string $sourceBranch, string $targetBranch, string $title): array
    {
        $username = Arr::get($repository, 'owner');
        $name = Arr::get($repository, 'name');

        return $this->client->pullRequest()->create(
            $username,
            $name,
            [
                'title' => $title,
                'head' => $sourceBranch,
                'base' => $targetBranch
            ]
        );
    }

    /**
     * @inheritDoc
     */
    public function makeBranch(array $repository, array $sourceBranch, string $title): string
    {
        $username = Arr::get($repository, 'owner');
        $name = Arr::get($repository, 'name');

        $this->client->gitData()->references()->create(
            $username,
            $name,
            [
                'ref' => "refs/heads/{$title}",
                'sha' => $sourceBranch['ref'],
            ]
        );

        return $title;
    }

    /**
     * @inheritDoc
     */
    public function grabDiff(array $repository, string $firsCommit, string $secondCommit): string
    {
        $username = Arr::get($repository, 'owner');
        $name = Arr::get($repository, 'name');

        $res = $this->client->repo()->commits()->compare($username, $name, $firsCommit, $secondCommit, 'application/vnd.github.VERSION.diff');
        dd($res);

    }


}



