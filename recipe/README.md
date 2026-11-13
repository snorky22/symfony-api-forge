# Symfony Flex recipe

The recipe of `snorky22/symfony-api-forge`, in the layout of
[symfony/recipes-contrib](https://github.com/symfony/recipes-contrib): `snorky22/symfony-api-forge/<version>/`.
This folder is not shipped in the Composer package (`.gitattributes`: `export-ignore`).

On `composer require snorky22/symfony-api-forge`, Flex then:

- registers `ApiForgeBundle` in `config/bundles.php`;
- copies `config/packages/api_forge.yaml` (the settings, with their default values, commented);
- says how to start: the web interface (`api-forge:serve`), the guide (`api-forge:guide`), where to override a template
  of the interface (`templates/bundles/ApiForgeBundle/`), and `symfony/webhook` to receive webhooks.

```text
recipe/snorky22/symfony-api-forge/0.3/
├── manifest.json                       what Flex does (bundle, files to copy, message after install)
└── config/packages/api_forge.yaml      copied into the project's config/packages/
```

## Publishing it, step by step

The recipe is published by a pull request to symfony/recipes-contrib. Once merged, Flex finds it for every project that
installs the package. Count one evening, then a few days of review.

### 1. Release the version the folder names

The folder `0.3/` applies to the versions **0.3.0 and above** (Flex takes the recipe of the highest folder not above
the installed version). So `0.3.0` must exist on Packagist first.

```bash
cd /path/to/symfony-api-forge
git checkout master && git merge paddle-webhooks     # the branch of the work, when it is ready
# CHANGELOG.md: rename "## Unreleased" to "## 0.3.0 - <date>"; commit
git tag v0.3.0
git push origin master --tags
```

Then on https://packagist.org/packages/snorky22/symfony-api-forge: check that `0.3.0` is listed (auto-update is on; if
not, click **Update**).

If the release is another number (0.4…), rename the folder to it before step 3: `recipe/snorky22/symfony-api-forge/0.4/`.

### 2. Check the recipe once more

- `manifest.json` is valid JSON:
  `php -r 'json_decode(file_get_contents("recipe/snorky22/symfony-api-forge/0.3/manifest.json"), flags: JSON_THROW_ON_ERROR); echo "ok\n";'`
- `config/packages/api_forge.yaml`: the keys and defaults match the bundle's configuration (`src/ApiForgeBundle.php`,
  `configure()`). Update it if a setting was added since.
- The bundle class in `manifest.json` (`Snorky22\ApiForge\ApiForgeBundle`) and its environments (`["all"]`; or
  `["dev"]` if the bundle should be enabled in development only: the generated code needs only `Core`, not the bundle).

### 3. Fork recipes-contrib and add the folder

```bash
# on GitHub: fork https://github.com/symfony/recipes-contrib (button "Fork")
git clone https://github.com/snorky22/recipes-contrib.git
cd recipes-contrib
git checkout -b snorky22-symfony-api-forge
mkdir -p snorky22/symfony-api-forge
cp -R /path/to/symfony-api-forge/recipe/snorky22/symfony-api-forge/0.3 snorky22/symfony-api-forge/
git add snorky22/symfony-api-forge
git commit -m "Add recipe for snorky22/symfony-api-forge"
git push origin snorky22-symfony-api-forge
```

Copy only the version folder: not this README.

### 4. Open the pull request

On GitHub, from the pushed branch: **Compare & pull request**, base `symfony/recipes-contrib` `main`.

- Title: `Add recipe for snorky22/symfony-api-forge`
- Description (to paste and adapt):

  > Recipe for snorky22/symfony-api-forge, a Symfony bundle that generates services for REST APIs and their
  > webhooks from documentation examples (https://github.com/snorky22/symfony-api-forge).
  > It registers the bundle, copies `config/packages/api_forge.yaml` (the settings with their defaults, commented),
  > and tells how to start (`api-forge:serve`, `api-forge:guide`).

The repository's checks run on the pull request (manifest format, files). If one fails, its message says what to
change: fix it in the fork's branch and push again, the pull request follows.

### 5. Test it before it is merged

A bot comments on the pull request with the command to try the recipe in a project before the merge: it points Flex
to the recipes of this pull request. In a fresh project:

```bash
symfony new recipe-test --webapp && cd recipe-test     # or: composer create-project symfony/skeleton recipe-test
# the command of the bot's comment, of the form:
composer config --json extra.symfony.endpoint '["https://raw.githubusercontent.com/symfony/recipes-contrib/flex/pull-<number>/index.json", "flex://defaults"]'
composer require snorky22/symfony-api-forge
```

Check:

- Flex asks to run the recipe (a **contrib** recipe: answer `y`, or `a` to always accept contrib recipes);
- `config/bundles.php` has `Snorky22\ApiForge\ApiForgeBundle::class => ['all' => true]`;
- `config/packages/api_forge.yaml` is there;
- the message after the install shows the lines of `post-install-output`;
- `php bin/console api-forge:guide` runs.

Then remove the endpoint from the test project (`composer config --unset extra.symfony.endpoint`).

### 6. After the merge

- Projects that install the package get the recipe. Flex asks once per project to allow contrib recipes
  (`composer config extra.symfony.allow-contrib true` accepts them without asking).
- Update the README's installation section if it says the recipe is not published yet.
- A later change of the recipe is a new pull request with a **new version folder** (e.g. `0.5/`), copied from the
  previous one and changed; the old folder stays for the older versions of the package.
