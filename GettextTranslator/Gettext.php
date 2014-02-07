<?php

namespace GettextTranslator;

use Nette;
use Nette\Utils\Strings;

class Gettext extends Nette\Object implements Nette\Localization\ITranslator
{
	/* @var string */
	public static $namespace = 'GettextTranslator-Gettext';

	/** @var array */
	protected $files = array();

	/** @var string */
	protected $lang;

	/** @var array */
	protected $dictionary = array();

	/** @var bool */
	private $productionMode;

	/** @var bool */
	private $loaded = FALSE;

	/** @var Nette\Http\SessionSection */
	private $sessionStorage;

	/** @var Nette\Caching\Cache */
	private $cache;

	/** @var Nette\Http\Response */
	private $httpResponse;

	/** @var array */
	private $metadata;

	/** @var array { [ key => default ] } */
	private $metadataList = array(
		'Project-Id-Version' => '',
		'Report-Msgid-Bugs-To' => NULL,
		'POT-Creation-Date' => '',
		'Last-Translator' => '',
		'Language-Team' => '',
		'MIME-Version' => '1.0',
		'Content-Type' => 'text/plain; charset=UTF-8',
		'Content-Transfer-Encoding' => '8bit',
		'Plural-Forms' => 'nplurals=3; plural=((n==1) ? 0 : (n>=2 && n<=4 ? 1 : 2));',
		'X-Poedit-Language' => NULL,
		'X-Poedit-Country' => NULL,
		'X-Poedit-SourceCharset' => NULL,
		'X-Poedit-KeywordsList' => NULL
	);


	public function __construct(Nette\Http\Session $session, Nette\Caching\IStorage $cacheStorage, Nette\Http\Response $httpResponse)
	{
		$this->sessionStorage = $sessionStorage = $session->getSection(self::$namespace);
		$this->cache = new Nette\Caching\Cache($cacheStorage, self::$namespace);
		$this->httpResponse = $httpResponse;

		if (!isset($sessionStorage->newStrings) || !is_array($sessionStorage->newStrings)) {
			$sessionStorage->newStrings = array();
		}
	}


	/**
	 * Add file to parse
	 * @param string $dir
	 * @param string $identifier
	 * @return $this
	 * @throws \InvalidArgumentException
	 */
	public function addFile($dir, $identifier)
	{
		if (isset($this->files[$identifier])) {
			throw new \InvalidArgumentException("Language file identified '$identifier' is already registered.");
		}

		if (is_dir($dir)) {
			$this->files[$identifier] = $dir;

		} else {
			throw new \InvalidArgumentException("Directory '$dir' doesn't exist.");
		}

		return $this;
	}


	/**
	 * Get current language
	 * @return string
	 * @throws Nette\InvalidStateException
	 */
	public function getLang()
	{
		if (empty($this->lang)) {
			throw new Nette\InvalidStateException('Language must be defined.');
		}

		return $this->lang;
	}


	/**
	 * Set new language
	 * @return this
	 */
	public function setLang($lang)
	{
		if (empty($lang)) {
			throw new Nette\InvalidStateException('Language must be nonempty string.');
		}

		if ($this->lang === $lang) {
			return;
		}

		$this->lang = $lang;
		$this->dictionary = array();
		$this->loaded = FALSE;

		return $this;
	}


	/**
	 * Set production mode (has influence on cache usage)
	 * @param bool  $mode
	 * @return this
	 */
	public function setProductionMode($mode)
	{
		$this->productionMode = (bool) $mode;
		return $this;
	}


	/**
	 * Load data
	 */
	protected function loadDictonary()
	{
		if (!$this->loaded) {
			if (empty($this->files)) {
				throw new Nette\InvalidStateException('Language file(s) must be defined.');
			}

			if ($this->productionMode && isset($this->cache['dictionary-' . $this->lang])) {
				$this->dictionary = $this->cache['dictionary-' . $this->lang];

			} else {
				$files = array();
				foreach ($this->files as $identifier => $dir) {
					$path = "$dir/$this->lang.$identifier.mo";
					if (file_exists($path)) {
						$this->parseFile($path, $identifier);
						$files[] = $path;
					}
				}

				if ($this->productionMode) {
					$this->cache->save('dictionary-' . $this->lang, $this->dictionary, array(
						'expire' => time() * 60 * 60 * 2,
						'files' => $files,
						'tags' => array('dictionary-' . $this->lang)
					));
				}
			}

			$this->loaded = TRUE;
		}
	}


	/**
	 * Parse dictionary file
	 * @param string $file file path
	 * @param string
	 */
	protected function parseFile($file, $identifier)
	{
		$f = @fopen($file, 'rb');
		if (@filesize($file) < 10) {
			throw new \InvalidArgumentException("'$file' is not a gettext file.");
		}

		$endian = FALSE;
		$read = function ($bytes) use ($f, $endian) {
			$data = fread($f, 4 * $bytes);
			return $endian === FALSE ? unpack('V' . $bytes, $data) : unpack('N' . $bytes, $data);
		};

		$input = $read(1);
		if (Strings::lower(substr(dechex($input[1]), -8)) == '950412de') {
			$endian = FALSE;

		} elseif (Strings::lower(substr(dechex($input[1]), -8)) == 'de120495') {
			$endian = TRUE;

		} else {
			throw new \InvalidArgumentException("'$file' is not a gettext file.");
		}

		$input = $read(1);

		$input = $read(1);
		$total = $input[1];

		$input = $read(1);
		$originalOffset = $input[1];

		$input = $read(1);
		$translationOffset = $input[1];

		fseek($f, $originalOffset);
		$orignalTmp = $read(2 * $total);
		fseek($f, $translationOffset);
		$translationTmp = $read(2 * $total);

		for ($i = 0; $i < $total; ++$i) {
			if ($orignalTmp[$i * 2 + 1] != 0) {
				fseek($f, $orignalTmp[$i * 2 + 2]);
				$original = @fread($f, $orignalTmp[$i * 2 + 1]);

			} else {
				$original = '';
			}

			if ($translationTmp[$i * 2 + 1] != 0) {
				fseek($f, $translationTmp[$i * 2 + 2]);
				$translation = fread($f, $translationTmp[$i * 2 + 1]);
				if ($original === '') {
					$this->parseMetadata($translation, $identifier);
					continue;
				}

				$original = explode("\0", $original);
				$translation = explode("\0", $translation);

				$key = isset($original[0]) ? $original[0] : $original;
				$this->dictionary[$key]['original'] = $original;
				$this->dictionary[$key]['translation'] = $translation;
				$this->dictionary[$key]['file'] = $identifier;
			}
		}
	}


	/**
	 * Metadata parser
	 * @param string $input
	 * @param string
	 */
	private function parseMetadata($input, $identifier)
	{
		$input = trim($input);

		$input = preg_split('/[\n,]+/', $input);
		foreach ($input as $metadata) {
			$pattern = ': ';
			$tmp = preg_split("($pattern)", $metadata);
			$this->metadata[$identifier][trim($tmp[0])] = count($tmp) > 2 ? ltrim(strstr($metadata, $pattern), $pattern) : $tmp[1];
		}
	}


	/**
	 * Translate given string
	 * @param string $message
	 * @param int $form plural form (positive number)
	 * @return string
	 */
	public function translate($message, $form = 1)
	{
		$this->loadDictonary();
		$files = array_keys($this->files);

		$message = (string)$message;
		$message_plural = NULL;
		if (is_array($form) && $form !== NULL) {
			$message_plural = current($form);
			$form = (int)end($form);
		} elseif (is_numeric($form)) {
			$form = (int)$form;
		} elseif (!is_int($form) || $form === NULL) {
			$form = 1;
		}

		if (!empty($message) && isset($this->dictionary[$message])) {
			$tmp = preg_replace('/([a-z]+)/', '$$1', "n=$form;" . $this->metadata[$files[0]]['Plural-Forms']);
			eval($tmp);

			$message = $this->dictionary[$message]['translation'];
			if (!empty($message)) {
				$message = (is_array($message) && $plural !== NULL && isset($message[$plural])) ? $message[$plural] : $message;
			}

		} else {
			if (!$this->httpResponse->isSent() || $this->sessionStorage) {
				if (!isset($this->sessionStorage->newStrings[$this->lang])) {
					$this->sessionStorage->newStrings[$this->lang] = array();
				}
				$this->sessionStorage->newStrings[$this->lang][$message] = empty($message_plural) ? array($message) : array($message, $message_plural);
			}

			if ($form > 1 && !empty($message_plural)) {
				$message = $message_plural;
			}
		}

		if (is_array($message)) {
			$message = current($message);
		}

		$args = func_get_args();
		if (count($args) > 1) {
			array_shift($args);
			if (is_array(current($args)) || current($args) === NULL) {
				array_shift($args);
			}

			if (count($args) == 1 && is_array(current($args))) {
				$args = current($args);
			}

			$message = str_replace(array('%label', '%name', '%value'), array('#label', '#name', '#value'), $message);
			if (count($args) > 0 && $args != NULL) {
				$message = vsprintf($message, $args);
			}
			$message = str_replace(array('#label', '#name', '#value'), array('%label', '%name', '%value'), $message);
		}

		return $message;
	}


	/**
	 * Get count of plural forms
	 * @return int
	 */
	public function getVariantsCount()
	{
		$this->loadDictonary();
		$files = array_keys($this->files);

		if (isset($this->metadata[$files[0]]['Plural-Forms'])) {
			return (int) substr($this->metadata[$files[0]]['Plural-Forms'], 9, 1);
		}

		return 1;
	}


	/**
	 * Get translations strings
	 * @return array
	 */
	public function getStrings($file = NULL)
	{
		$this->loadDictonary();

		$newStrings = array();
		$result = array();

		if (isset($this->sessionStorage->newStrings[$this->lang])) {
			foreach (array_keys($this->sessionStorage->newStrings[$this->lang]) as $original) {
				if (trim($original) != '') {
					$newStrings[$original] = FALSE;
				}
			}
		}

		foreach ($this->dictionary as $original => $data) {
			if (trim($original) != '') {
				if ($file && $data['file'] === $file) {
					$result[$original] = $data['translation'];

				} else {
					$result[$data['file']][$original] = $data['translation'];
				}
			}
		}

		if ($file) {
			return array_merge($newStrings, $result);

		} else {
			foreach ($this->getFiles() as $identifier => $path) {
				if (!isset($result[$identifier])) {
					$result[$identifier] = array();
				}
			}

			return array('newStrings' => $newStrings) + $result;
		}
	}


	/**
	 * Get loaded files
	 * @return array
	 */
	public function getFiles()
	{
		$this->loadDictonary();
		return $this->files;
	}


	/**
	 * Set translation string(s)
	 * @param string|array $message original string(s)
	 * @param string|array $string translation string(s)
	 * @param string
	 */
	public function setTranslation($message, $string, $file)
	{
		$this->loadDictonary();

		if (isset($this->sessionStorage->newStrings[$this->lang]) && array_key_exists($message, $this->sessionStorage->newStrings[$this->lang])) {
			$message = $this->sessionStorage->newStrings[$this->lang][$message];
		}

		$key = is_array($message) ? $message[0] : $message;
		$this->dictionary[$key]['original'] = (array) $message;
		$this->dictionary[$key]['translation'] = (array) $string;
		$this->dictionary[$key]['file'] = $file;
	}


	/**
	 * Save dictionary
	 * @param string
	 */
	public function save($file)
	{
		if (!$this->loaded) {
			throw new Nette\InvalidStateException('Nothing to save, translations are not loaded.');
		}

		if (!isset($this->files[$file])) {
			throw new \InvalidArgumentException("Gettext file identified as '$file' does not exist.");
		}

		$dir = $this->files[$file];
		$path = "$dir/$this->lang.$file";

		$this->buildMOFile("$path.mo", $file);
		$this->buildPOFile("$path.po", $file);

		if (isset($this->sessionStorage->newStrings[$this->lang])) {
			unset($this->sessionStorage->newStrings[$this->lang]);
		}

		if ($this->productionMode) {
			$this->cache->clean(array(
				'tags' => 'dictionary-' . $this->lang
			));
		}
	}


	/**
	 * Generate gettext metadata array
	 * @param string
	 * @return array
	 */
	private function generateMetadata($identifier)
	{
		$result = array();
		$result[] = 'PO-Revision-Date: ' . date('Y-m-d H:iO');

		foreach ($this->metadataList as $key => $default) {
			if (isset($this->metadata[$identifier][$key])) {
				$result[] = $key . ': ' . $this->metadata[$identifier][$key];

			} elseif ($default) {
				$result[] = $key . ': ' . $default;
			}
		}

		return $result;
	}


	/**
	 * Build gettext PO file
	 * @param string $file
	 * @param string
	 */
	private function buildPOFile($file, $identifier)
	{
		$po = "# Gettext keys exported by GettextTranslator and Translation Panel\n" . "# Created: " . date('Y-m-d H:i:s') . "\n" . 'msgid ""' . "\n" . 'msgstr ""' . "\n";
		$po .= '"' . implode('\n"' . "\n" . '"', $this->generateMetadata($identifier)) . '\n"' . "\n\n\n";

		foreach ($this->dictionary as $message => $data) {
			if ($data['file'] !== $identifier) {
				continue;
			}

			$po .= 'msgid "' . str_replace(array('"'), array('\"'), $message) . '"' . "\n";

			if (is_array($data['original']) && count($data['original']) > 1) {
				$po .= 'msgid_plural "' . str_replace(array('"'), array('\"'), end($data['original'])) . '"' . "\n";
			}

			if (!is_array($data['translation'])) {
				$po .= 'msgstr "' . str_replace(array('"'), array('\"'), $data['translation']) . '"' . "\n";

			} elseif (count($data['translation']) < 2) {
				$po .= 'msgstr "' . str_replace(array('"'), array('\"'), current($data['translation'])) . '"' . "\n";

			} else {
				$i = 0;
				foreach ($data['translation'] as $string) {
					$po .= 'msgstr[' . $i . '] "' . str_replace(array('"'), array('\"'), $string) . '"' . "\n";
					$i++;
				}
			}

			$po .= "\n";
		}

		if (isset($this->sessionStorage->newStrings[$this->lang])) {
			foreach ($this->sessionStorage->newStrings[$this->lang] as $original) {
				if (trim(current($original)) != "" && !\array_key_exists(current($original), $this->dictionary)) {
					$po .= 'msgid "' . str_replace(array('"'), array('\"'), current($original)) . '"' . "\n";

					if (count($original) > 1) {
						$po .= 'msgid_plural "' . str_replace(array('"'), array('\"'), end($original)) . '"' . "\n";
					}

					$po .= "msgstr \"\"\n";
					$po .= "\n";
				}
			}
		}

		file_put_contents($file, $po);
	}


	/**
	 * Build gettext MO file
	 * @param string $file
	 * @param string
	 */
	private function buildMOFile($file, $identifier)
	{
		$dictionary = array_filter($this->dictionary, function ($data) use ($identifier) {
			return $data['file'] === $identifier;
		});

		ksort($dictionary);

		$metadata = implode("\n", $this->generateMetadata($identifier));

		$items = count($dictionary) + 1;
		$ids = Strings::chr(0x00);
		$strings = $metadata . Strings::chr(0x00);
		$idsOffsets = array(0, 28 + $items * 16);
		$stringsOffsets = array(array(0, strlen($metadata)));

		foreach ($dictionary as $key => $value) {
			$id = $key;
			if (is_array($value['original']) && count($value['original']) > 1) {
				$id .= Strings::chr(0x00) . end($value['original']);
			}

			$string = implode(Strings::chr(0x00), $value['translation']);
			$idsOffsets[] = strlen($id);
			$idsOffsets[] = strlen($ids) + 28 + $items * 16;
			$stringsOffsets[] = array(strlen($strings), strlen($string));
			$ids .= $id . Strings::chr(0x00);
			$strings .= $string . Strings::chr(0x00);
		}

		$valuesOffsets = array();
		foreach ($stringsOffsets as $offset) {
			list ($all, $one) = $offset;
			$valuesOffsets[] = $one;
			$valuesOffsets[] = $all + strlen($ids) + 28 + $items * 16;
		}
		$offsets = array_merge($idsOffsets, $valuesOffsets);

		$mo = pack('Iiiiiii', 0x950412de, 0, $items, 28, 28 + $items * 8, 0, 28 + $items * 16);
		foreach ($offsets as $offset) {
			$mo .= pack('i', $offset);
		}

		file_put_contents($file, $mo . $ids . $strings);
	}

}
