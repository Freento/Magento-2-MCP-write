<?php

declare(strict_types=1);

namespace Freento\McpWrite\Model\Tool;

use Freento\Mcp\Api\ToolResultInterface;
use Freento\McpWrite\Api\WriteToolInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Validation\ValidationException;

/**
 * Base class for tools that change store data.
 *
 * - refuses to run while write tools are disabled in admin config
 * - supports dry_run: the tool validates and reports, but must not save
 * - runs the tool body under the area code returned by getAreaCode()
 * - turns exceptions into readable error results
 */
abstract class AbstractWriteTool implements WriteToolInterface
{
    /**
     * @param Context $context
     */
    public function __construct(
        protected readonly Context $context
    ) {
    }

    /**
     * Tool body. Return the result text; throw LocalizedException for user-facing errors.
     *
     * @param array $arguments
     * @param bool $dryRun
     * @return string
     * @throws LocalizedException
     */
    abstract protected function doExecute(array $arguments, bool $dryRun): string;

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): ToolResultInterface
    {
        if (!$this->context->config->isEnabled()) {
            return $this->context->resultFactory->createError(
                'MCP write tools are disabled. Enable them in Stores > Configuration > Freento > MCP > MCP Write Tools.'
            );
        }

        $dryRun = $this->toBool($arguments['dry_run'] ?? false);

        try {
            $areaCode = $this->getAreaCode();
            $text = (string)($areaCode === null
                ? $this->doExecute($arguments, $dryRun)
                : $this->context->appState->emulateAreaCode(
                    $areaCode,
                    fn () => $this->doExecute($arguments, $dryRun)
                ));
        } catch (ValidationException $e) {
            $text = 'Error: ' . $e->getMessage();
            foreach ($e->getErrors() as $error) {
                $text .= "\n- " . $error->getMessage();
            }
            return $this->context->resultFactory->createError($text);
        } catch (\Throwable $e) {
            return $this->context->resultFactory->createError('Error: ' . $e->getMessage());
        }

        if ($dryRun) {
            $text = "DRY RUN: nothing was saved. Call again with dry_run=false to apply.\n\n" . $text;
        }

        return $this->context->resultFactory->createText($text);
    }

    /**
     * Area code to emulate while the tool body runs; null runs it in the area of the request
     *
     * @return string|null
     */
    protected function getAreaCode(): ?string
    {
        return Area::AREA_ADMINHTML;
    }

    /**
     * JSON schema fragment for the dry_run argument
     *
     * @return array
     */
    protected function dryRunProperty(): array
    {
        return [
            'dry_run' => [
                'type' => 'boolean',
                'description' => 'If true, check the arguments and the store data they reference and report what '
                    . 'would change, without saving. Validation that only happens on save (attribute backends, '
                    . 'third-party plugins) is not run. Default: false.',
            ],
        ];
    }

    /**
     * Read a required non-empty string argument
     *
     * @param array $arguments
     * @param string $key
     * @return string
     * @throws LocalizedException
     */
    protected function requireString(array $arguments, string $key): string
    {
        $value = $this->stringArgument($arguments, $key);

        if ($value === '') {
            throw new LocalizedException(__('Argument "%1" is required.', $key));
        }

        return $value;
    }

    /**
     * Read an optional string argument, trimmed; a missing or null one is ""
     *
     * @param array $arguments
     * @param string $key
     * @return string
     * @throws LocalizedException
     */
    protected function stringArgument(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? '';
        if (!is_scalar($value)) {
            throw new LocalizedException(
                __('Argument "%1" must be a string, got %2.', $key, get_debug_type($value))
            );
        }

        return trim((string)$value);
    }

    /**
     * Interpret a loosely typed boolean (true, "yes", "1", 1 ...)
     *
     * @param mixed $value
     * @return bool
     */
    protected function toBool(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'on'], true);
        }

        return (bool)$value;
    }
}
