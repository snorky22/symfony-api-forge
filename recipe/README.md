# Symfony Flex recipe

The recipe of `snorky22/symfony-api-forge`, in the layout of
[symfony/recipes-contrib](https://github.com/symfony/recipes-contrib): `snorky22/symfony-api-forge/<version>/`.

On `composer require snorky22/symfony-api-forge`, Flex then:

- registers `ApiForgeBundle` in `config/bundles.php`;
- copies `config/packages/api_forge.yaml` (the settings, with their default values, commented);
- says how to start: the web interface (`api-forge:serve`), the guide (`api-forge:guide`), where to override a template
  of the interface (`templates/bundles/ApiForgeBundle/`), and `symfony/webhook` to receive webhooks.

To publish it, open a pull request on symfony/recipes-contrib with the `snorky22/symfony-api-forge/0.3/` folder, once
the version it names is released on Packagist (Flex picks the recipe of the highest version not above the one
installed). Until then, the README's manual installation applies.
