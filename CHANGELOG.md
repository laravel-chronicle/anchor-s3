# Changelog

All notable changes to `laravel-chronicle/anchor-s3` are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

---

## [1.1.1] - 2026-10-28

### Fixed
- Include the affected bucket name in the exception message when S3 PutObject returns no ETag or VersionId. Fixes #5, thanks @halyna-tech (#9).

---

## [1.1.0] - 2026-07-15

### Added

- Three-tier test layout via Pest groups (`unit`, `localstack`, `aws`) with Composer scripts `test:unit` (zero-config default, excludes the network tiers), `test:localstack`, `test:aws`, and `test:all`. The default `composer test` still runs lint + types + unit only, so a first contribution needs no Docker, no AWS account, and no network.
- Real-AWS characterization tests documenting that anchoring against a bucket without Object Lock (or without versioning) leaves no deletable object at the target key - the refusal is enforced by AWS at `PutObject`, because the adapter sends retention on the same write. These tests fail loudly if a future change ever decouples retention from the write.
- LocalStack test tier (`composer test:localstack`) backed by a pinned `localstack/localstack:3.8.1` service in `docker-compose.yml`: verifies the S3 request/response conversation - object written to the expected key with the expected digest, Governance retention transmitted and echoed by the API, and error paths (missing bucket, unreachable endpoint) surfacing as thrown exceptions. Every test in the tier carries a header stating LocalStack does not enforce Object Lock, so no test here can be mistaken for validating immutability. The tier skips cleanly with an explanatory message when LocalStack is not reachable.
- Real-AWS test tier (`composer test:aws`) that validates the core immutability claim against live S3 Object Lock: anchoring an object and then asserting the object and its version cannot be deleted without the governance bypass, that Governance retention is recorded and readable, that a tampered checkpoint fails verification while the genuine one still passes, and that credentials lacking `s3:PutObjectRetention` fail loudly instead of writing an unprotected object. The tier is Governance-mode only - the bootstrap fails hard and writes nothing if `ANCHOR_S3_TEST_RETENTION_MODE` is not exactly `GOVERNANCE` - reads its bucket from `ANCHOR_S3_TEST_BUCKET` with no default, and cleans up in a `finally` so a failing assertion still tears the object down.
- CI runs the unit and LocalStack tiers on every push and pull request (including forks - no secrets needed), and the real-AWS tier only on push to `main` and a nightly schedule, gated, so it skips cleanly when AWS secrets are absent rather than producing red checks on external contributions.
- `.env.example` documenting every variable the network tiers read (LocalStack endpoint, AWS credentials/region, the no-default `ANCHOR_S3_TEST_BUCKET`, and the Governance-only `ANCHOR_S3_TEST_RETENTION_MODE`), and an updated `CONTRIBUTING.md` describing the three tiers, when a contributor needs each, the AWS bucket + scoped-IAM + lifecycle setup, and the full Governance-mode warning. `.env` is git-ignored.

### Changed

- **BREAKING (behavioral default): the default Object Lock retention mode is now `GOVERNANCE`, not `COMPLIANCE`.** An anchor configured without an explicit `mode` previously wrote objects under COMPLIANCE retention, which cannot be removed by anyone - including the account root user - until it expires; it now writes under GOVERNANCE retention, which a principal holding `s3:BypassGovernanceRetention` can lift. Any `mode` value other than the exact string `COMPLIANCE` resolves to `GOVERNANCE`, so an unrecognized value or typo fails safe toward the recoverable mode rather than the irreversible one. To keep the previous behavior, set `mode` to `COMPLIANCE` explicitly. Objects already written under COMPLIANCE in 1.0.0 are immutable and unaffected; only the mode applied to new, unconfigured anchors changes.

---

## [1.0.0] - 2026-06-09

### Added

- Package scaffolding: Composer metadata, PHPStan (level 10) + Pest/testbench tooling (`TestCase` booting core + the adapter, with shared `ref()` / `makeMockS3Client()` helpers), and an `AnchorS3ServiceProvider` that binds a default `Aws\S3\S3Client` singleton from environment configuration. Depends on `laravel-chronicle/core` and `aws/aws-sdk-php` only.
- `S3ObjectLockAnchor` implementing `Chronicle\Contracts\AnchorProvider` (`name()` = `s3-object-lock`), with `__construct(array $config, Aws\S3\S3Client $client)` validating a required `bucket` and accepting `prefix` / `mode` (COMPLIANCE default) / `retain_days` (3650 default).
- `S3ObjectLockAnchor::anchor()` writes `CheckpointDigest::for($checkpoint)` to `{prefix}/{checkpoint-id}.digest` via `PutObject` with `ObjectLockMode` + `ObjectLockRetainUntilDate`, returning a receipt whose `reference` is `bucket/key@versionId` and whose `proof` is the object ETag.
- `S3ObjectLockAnchor::verify()` re-reads the exact object version named in the receipt (`GetObject`), confirms the stored bytes equal the recomputed `CheckpointDigest`, confirms Object Lock metadata is present, and confirms the ETag matches the receipt proof. Network errors / missing objects verify as `false`.
- README documenting Object-Lock bucket setup, the minimal IAM action set (no delete), provider registration in `chronicle.anchoring.providers`, and the anchor/verify trust model.

---

### Security

- End-to-end coverage proves an S3-Object-Lock-anchored checkpoint passes `chronicle:verify --anchors` against a mocked S3 client, and (via the unit suite) that a rewritten checkpoint whose recomputed digest no longer matches the immutable object FAILS verification - external WORM storage defeats a full in-database compromise.

[Unreleased]: https://github.com/laravel-chronicle/anchor-s3/compare/1.1.0...HEAD
[1.1.0]: https://github.com/laravel-chronicle/anchor-s3/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/laravel-chronicle/anchor-s3/releases/tag/1.0.0
