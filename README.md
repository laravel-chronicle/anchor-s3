# Chronicle S3 Object Lock Anchor

An [S3 Object Lock](https://docs.aws.amazon.com/AmazonS3/latest/userguide/object-lock.html)
(WORM) anchoring adapter for [`laravel-chronicle/core`](https://github.com/laravel-chronicle/core).
It writes each checkpoint's digest — `sha256(id . chain_hash . created_at)` — to a
**locked, versioned** S3 object in an independent trust domain. Even an attacker who
rewrites the ledger and re-signs every checkpoint with a valid key cannot alter the
locked object, so `chronicle:verify --anchors` fails on the tampered checkpoint.

## Installation

```bash
composer require laravel-chronicle/anchor-s3
```

The package auto-registers `AnchorS3ServiceProvider`, which binds a default
`Aws\S3\S3Client` from `AWS_DEFAULT_REGION` / standard AWS credentials.

## Bucket setup (one-time)

Object Lock requires a **versioned** bucket created with Object Lock enabled:

```bash
aws s3api create-bucket \
  --bucket my-chronicle-anchors \
  --object-lock-enabled-for-bucket \
  --region eu-west-1 \
  --create-bucket-configuration LocationConstraint=eu-west-1

# (Optional) a default retention rule; per-object retain-until still applies.
aws s3api put-object-lock-configuration \
  --bucket my-chronicle-anchors \
  --object-lock-configuration '{"ObjectLockEnabled":"Enabled","Rule":{"DefaultRetention":{"Mode":"COMPLIANCE","Days":3650}}}'
```

- **COMPLIANCE** (default): no one — not even the root account — can delete or
  shorten retention until it expires. Use for regulated/SOC 2 profiles.
- **GOVERNANCE**: principals with `s3:BypassGovernanceRetention` can override.

## Registration

Enable anchoring and register the provider in your published `config/chronicle.php`:

```php
'anchoring' => [
    'enabled' => true,
    'providers' => [
        's3-object-lock' => [
            'provider' => \Chronicle\AnchorS3\S3ObjectLockAnchor::class,
            'bucket' => env('CHRONICLE_S3_ANCHOR_BUCKET'),
            'prefix' => 'chronicle/anchors',   // optional (default 'chronicle/anchors')
            'mode' => 'COMPLIANCE',            // or 'GOVERNANCE'
            'retain_days' => 3650,             // optional
        ],
    ],
],
```

New checkpoints are then anchored automatically (queued); or anchor on demand with
`php artisan chronicle:checkpoint --anchor`, retry with `chronicle:anchor:retry`, and
attest stored anchors with `chronicle:anchor:verify` / `chronicle:verify --anchors`.

## Required IAM actions

On the anchor bucket (`arn:aws:s3:::my-chronicle-anchors/*` and the bucket ARN):

| Action | Used by | Why |
|--------|---------|-----|
| `s3:PutObject` | `anchor()` | Write the digest object |
| `s3:PutObjectRetention` | `anchor()` | Apply per-object Object Lock retention |
| `s3:GetObject` | `verify()` | Re-read the exact object version |
| `s3:GetObjectVersion` | `verify()` | Read by `VersionId` |
| `s3:GetObjectRetention` | `verify()` | Confirm lock metadata |

Grant **no** `s3:DeleteObject*` — anchors are write-once by design.

## How it works

- `anchor()` → `PutObject` of the digest with `ObjectLockMode` + `ObjectLockRetainUntilDate`.
  Receipt: `reference = "bucket/key@versionId"`, `proof = ETag`.
- `verify()` → `GetObject` of that exact version; passes only if the stored bytes equal
  the recomputed digest **and** lock metadata is present **and** the ETag matches.

`verify()` makes one S3 read; it is **not** offline (unlike the core RFC 3161 anchor),
which is the deliberate trade for an independent, account-isolated trust domain.

## Testing

```bash
composer test       # Pest (mocked S3 — never touches a real bucket)
composer analyse    # PHPStan level 10
```
