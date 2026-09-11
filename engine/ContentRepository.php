<?php
/**
 * Loader and cache for the JSON game content.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

/**
 * The engine's only door to the filesystem.
 *
 * Everything is read from one injected directory (`MRP_DATA_DIR` in the plugin, a fixture
 * directory in tests), decoded, validated once against spec section 7 and cached for the
 * lifetime of the repository. Nothing here touches WordPress, superglobals or the clock.
 *
 * Lookups return plain arrays exactly as authored, with one convenience: collections that
 * are authored as lists (`countries`, `outlets`, and the per-file events) are returned
 * keyed by id, in file order.
 */
final class ContentRepository
{
    /** Sub-directory holding one scenario per file. */
    const SCENARIOS_DIR = 'scenarios';

    /** Sub-directory holding one event per file. */
    const EVENTS_DIR = 'events';

    /** Advisors document, relative to the data directory. */
    const ADVISORS_FILE = 'advisors/advisors.json';

    /** Countries document, relative to the data directory. */
    const COUNTRIES_FILE = 'countries/countries.json';

    /** Media outlets document, relative to the data directory. */
    const OUTLETS_FILE = 'media/outlets.json';

    /** Key inside `advisors.json` holding the named sets. */
    const ADVISOR_SETS_KEY = 'sets';

    /** Default advisor set key. */
    const DEFAULT_ADVISOR_SET = 'default';

    /** Absolute path to the data directory, with a trailing separator. */
    private string $dataDir;

    /** Scenario documents, keyed by id. @var array<string, array> */
    private array $scenarios = [];

    /** Event documents, keyed by id. @var array<string, array> */
    private array $events = [];

    /** Whether every scenario file has been read. */
    private bool $scenariosLoaded = false;

    /** Whether every event file has been read. */
    private bool $eventsLoaded = false;

    /** Advisors document, once read. @var array|null */
    private ?array $advisors = null;

    /** Countries keyed by id, once read. @var array|null */
    private ?array $countries = null;

    /** Outlets keyed by id, once read. @var array|null */
    private ?array $outlets = null;

    /**
     * @param string $dataDir Directory containing `scenarios/`, `events/`, `advisors/`,
     *                        `countries/` and `media/`.
     */
    public function __construct(string $dataDir)
    {
        $this->dataDir = rtrim($dataDir, "/\\") . DIRECTORY_SEPARATOR;
    }

    /**
     * The data directory this repository reads from.
     *
     * @return string Path with a trailing separator.
     */
    public function dataDir(): string
    {
        return $this->dataDir;
    }

    /**
     * One scenario.
     *
     * @param string $id Scenario id.
     *
     * @return array Scenario document.
     *
     * @throws EngineException `unknown_scenario` when there is no such scenario,
     *                         `invalid_content` when the document fails validation.
     */
    public function scenario(string $id): array
    {
        if (isset($this->scenarios[$id])) {
            return $this->scenarios[$id];
        }

        $relative = self::SCENARIOS_DIR . '/' . $id . JsonFile::EXTENSION;

        if (!$this->fileExists($relative)) {
            if (!$this->scenariosLoaded) {
                $this->scenarios();
            }

            if (isset($this->scenarios[$id])) {
                return $this->scenarios[$id];
            }

            throw new EngineException(
                EngineException::UNKNOWN_SCENARIO,
                sprintf('Unknown scenario "%s".', $id)
            );
        }

        $document = $this->readDocument($relative);
        ContentValidator::scenario($document, $relative);

        if ($document['id'] !== $id) {
            throw EngineException::invalidContent($relative, 'id', 'does not match the file name');
        }

        $this->scenarios[$id] = $document;

        return $document;
    }

    /**
     * Every scenario, keyed by id.
     *
     * @return array<string, array>
     *
     * @throws EngineException `invalid_content`
     */
    public function scenarios(): array
    {
        if ($this->scenariosLoaded) {
            return $this->scenarios;
        }

        $loaded = [];

        foreach ($this->listFiles(self::SCENARIOS_DIR) as $relative) {
            $document = $this->readDocument($relative);
            ContentValidator::scenario($document, $relative);
            $this->rememberUnique($loaded, $document, $relative);
        }

        $this->scenarios       = $loaded;
        $this->scenariosLoaded = true;

        return $this->scenarios;
    }

    /**
     * One event.
     *
     * @param string $id Event id.
     *
     * @return array Event document.
     *
     * @throws EngineException `unknown_event` when there is no such event,
     *                         `invalid_content` when the document fails validation.
     */
    public function event(string $id): array
    {
        if (isset($this->events[$id])) {
            return $this->events[$id];
        }

        $relative = self::EVENTS_DIR . '/' . $id . JsonFile::EXTENSION;

        if ($this->fileExists($relative)) {
            $document = $this->readDocument($relative);
            ContentValidator::event($document, $relative);

            if ($document['id'] !== $id) {
                throw EngineException::invalidContent($relative, 'id', 'does not match the file name');
            }

            $this->events[$id] = $document;

            return $document;
        }

        if (!$this->eventsLoaded) {
            $this->events();
        }

        if (isset($this->events[$id])) {
            return $this->events[$id];
        }

        throw new EngineException(
            EngineException::UNKNOWN_EVENT,
            sprintf('Unknown event "%s".', $id)
        );
    }

    /**
     * Every event, keyed by id, loaded lazily from `events/*.json`.
     *
     * @return array<string, array>
     *
     * @throws EngineException `invalid_content`
     */
    public function events(): array
    {
        if ($this->eventsLoaded) {
            return $this->events;
        }

        $loaded = [];

        foreach ($this->listFiles(self::EVENTS_DIR) as $relative) {
            $document = $this->readDocument($relative);
            ContentValidator::event($document, $relative);
            $this->rememberUnique($loaded, $document, $relative);
        }

        $this->events       = $loaded;
        $this->eventsLoaded = true;

        return $this->events;
    }

    /**
     * Whether an event exists, without throwing.
     *
     * @param string $id Event id.
     *
     * @return bool
     */
    public function hasEvent(string $id): bool
    {
        if (isset($this->events[$id])) {
            return true;
        }

        return isset($this->events()[$id]);
    }

    /**
     * The advisors document.
     *
     * @return array Decoded `advisors.json`.
     *
     * @throws EngineException `invalid_content`
     */
    public function advisors(): array
    {
        if (null === $this->advisors) {
            $document = $this->readDocument(self::ADVISORS_FILE);
            ContentValidator::advisors($document, self::ADVISORS_FILE);
            $this->advisors = $document;
        }

        return $this->advisors;
    }

    /**
     * One named advisor set, in authored order.
     *
     * @param string $key Set key, e.g. `default`.
     *
     * @return array<int, array> Advisor objects.
     *
     * @throws EngineException `invalid_content` when the set does not exist.
     */
    public function advisorSet(string $key): array
    {
        $sets = $this->advisors()[self::ADVISOR_SETS_KEY];

        if (!isset($sets[$key])) {
            throw EngineException::invalidContent(
                self::ADVISORS_FILE,
                self::ADVISOR_SETS_KEY . '.' . $key,
                'advisor set does not exist'
            );
        }

        return array_values($sets[$key]);
    }

    /**
     * Every country, keyed by id, in file order.
     *
     * @return array<string, array>
     *
     * @throws EngineException `invalid_content`
     */
    public function countries(): array
    {
        if (null === $this->countries) {
            $document = $this->readDocument(self::COUNTRIES_FILE);
            ContentValidator::countries($document, self::COUNTRIES_FILE);
            $this->countries = self::keyById($document['countries']);
        }

        return $this->countries;
    }

    /**
     * One country.
     *
     * @param string $id Country id.
     *
     * @return array
     *
     * @throws EngineException `invalid_content` when the country does not exist.
     */
    public function country(string $id): array
    {
        $countries = $this->countries();

        if (!isset($countries[$id])) {
            throw EngineException::invalidContent(self::COUNTRIES_FILE, $id, 'country does not exist');
        }

        return $countries[$id];
    }

    /**
     * Every media outlet, keyed by id, in file order.
     *
     * @return array<string, array>
     *
     * @throws EngineException `invalid_content`
     */
    public function outlets(): array
    {
        if (null === $this->outlets) {
            $document = $this->readDocument(self::OUTLETS_FILE);
            ContentValidator::outlets($document, self::OUTLETS_FILE);
            $this->outlets = self::keyById($document['outlets']);
        }

        return $this->outlets;
    }

    /**
     * One media outlet.
     *
     * @param string $id Outlet id.
     *
     * @return array
     *
     * @throws EngineException `invalid_content` when the outlet does not exist.
     */
    public function outlet(string $id): array
    {
        $outlets = $this->outlets();

        if (!isset($outlets[$id])) {
            throw EngineException::invalidContent(self::OUTLETS_FILE, $id, 'outlet does not exist');
        }

        return $outlets[$id];
    }

    /**
     * Read and decode one JSON document.
     *
     * @param string $relative Path relative to the data directory.
     *
     * @return array Decoded document.
     *
     * @throws EngineException `invalid_content` when the file is unreadable or malformed.
     */
    private function readDocument(string $relative): array
    {
        return JsonFile::read($this->dataDir . $relative, $relative);
    }

    /**
     * List the JSON files in a content sub-directory, sorted for a deterministic load order.
     *
     * @param string $directory Sub-directory name.
     *
     * @return array<int, string> Paths relative to the data directory.
     */
    private function listFiles(string $directory): array
    {
        $relative = [];

        foreach (JsonFile::listIn($this->dataDir . $directory) as $path) {
            $relative[] = $directory . '/' . basename($path);
        }

        return $relative;
    }

    /**
     * Whether a content file exists and can be read.
     *
     * @param string $relative Path relative to the data directory.
     *
     * @return bool
     */
    private function fileExists(string $relative): bool
    {
        return JsonFile::exists($this->dataDir . $relative);
    }

    /**
     * Collect a document by its id, rejecting duplicates.
     *
     * The sweep methods build a fresh map and assign it afterwards, so a document already
     * cached by a single-id lookup is re-read rather than mistaken for a second file
     * claiming the same id.
     *
     * @param array  $cache    Map to write into, by reference.
     * @param array  $document Validated document.
     * @param string $relative File the document came from.
     *
     * @return void
     *
     * @throws EngineException `invalid_content` on a duplicate id.
     */
    private function rememberUnique(array &$cache, array $document, string $relative): void
    {
        $id = (string) $document['id'];

        if (isset($cache[$id])) {
            throw EngineException::invalidContent($relative, 'id', 'duplicate id "' . $id . '"');
        }

        $cache[$id] = $document;
    }

    /**
     * Re-key an authored list by each entry's id, preserving order.
     *
     * @param array $list Authored list.
     *
     * @return array<string, array>
     */
    private static function keyById(array $list): array
    {
        $keyed = [];

        foreach ($list as $entry) {
            $keyed[(string) $entry['id']] = $entry;
        }

        return $keyed;
    }
}
