# Wwwision.AssetUsage

Simple and fast asset usage index for the Neos Content Repository

## Usage

Install via [composer](https://getcomposer.org/):

    composer require wwwision/assetusage

**Important:** The usage index has to be updated manually initially. After that it will be kept in sync automatically

### Update usage index

    ./flow assetusage:updateIndex

### Reset usage index

    ./flow assetusage:resetIndex

## Disclaimer

This package is inspired by [flowpack/neos-asset-usage](https://packagist.org/packages/flowpack/neos-asset-usage) but
uses Doctrine DBAL directly and thus comes with some advantages:

* Much simpler architecture due to fewer abstraction levels
* Linear memory consumption (see https://github.com/Flowpack/Flowpack.Neos.AssetUsage/issues/3)
* Detects asset usages in text properties (see https://github.com/Flowpack/Flowpack.Neos.AssetUsage/issues/1)