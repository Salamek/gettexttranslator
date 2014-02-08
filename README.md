# Gettext Translator

Gettext Translator is tool that enables simple and user friendly translation of your texts via panel in debug bar. It works with the newest Nette 2.0. No need to edit or operate with .po/.mo files.

## Installation and usage

### Installation via composer:

	{
	    "require":{
	        "salamek/gettexttranslator"
	    }
	}

### Usage

1. Set up config.neon:

	common:
	    gettextTranslator:
	        lang: cs #default language
	        files:
	            front: %appDir%/lang # for module Front and other non-specified modules
	            admin: %appDir%/lang-admin # for module Admin
	        # optional with defaults
	        layout: horizontal # or: vertical
	        height: 450


2. Set up in bootstrap.php

	$configurator->onCompile[] = function ($configurator, $compiler) {
	    $compiler->addExtension('gettextTranslator', new GettextTranslator\DI\Extension);
	};

3. Set up in BasePresenter.php

	class BasePresenter extends Nette\Application\UI\Presenter
	{
	    /** @persistent */
	    public $lang;

	    /** @var GettextTranslator\Gettext */
	    protected $translator;


	    /**
	     * @param GettextTranslator\Gettext
	     */
	    public function injectTranslator(GettextTranslator\Gettext $translator)
	    {
	        $this->translator = $translator;
	    }


	    public function createTemplate($class = NULL)
	    {
	        $template = parent::createTemplate($class);

	        // if not set, the default language will be used
	        if (!isset($this->lang)) {
	            $this->lang = $this->translator->getLang();
	        } else {
	            $this->translator->setLang($this->lang);
	        }

	        $template->setTranslator($this->translator);

	        return $template;
	    }
	}

### Change language eg. in @template.latte

	Choose language:
	<a n:href="this, lang => en">English</a>
	<a n:href="this, lang => cs">Česky</a>

### How to translate a string

* In template

	{_"Login"}

	{_"piece", $number}
	1 piece <!-- $number = 1; -->
	2 pieces <!-- $number = 2; -->
	5 pieces <!-- $number = 5; -->

* In forms

	protected function createComponentMyForm()
	{
	    $form = new Form;
	    $form->setTranslator($this->translator);

	    // ...

	    return $form;
	}

* In components

	public function createTemplate($class = NULL)
	{
	    $template = parent::createTemplate($class);
	    $template->setTranslator($this->parent->translator); // $translator in presenter has to be public
	    // or $this->translator via construct/inject

	    return $template;
	}

* In flash message


	<div n:foreach="$flashes as $flash" class="alert {$flash->type} fade in">
		<button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
		{!_$flash->message}
	</div>

## Authors in alphabetic order

- Josef Kufner (jk@frozen-doe.net)
- Miroslav Paulík (https://github.com/castamir)
- Roman Sklenář (http://romansklenar.cz)
- Miroslav Smetana
- Jan Smitka
- Adam Schubert
- Patrik Votoček (patrik@votocek.cz)
- Tomáš Votruba (tomas.vot@gmail.com)
- Václav Vrbka (gmvasek@php-info.cz)


Under *New BSD License*
