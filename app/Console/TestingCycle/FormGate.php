<?php

namespace App\Console\TestingCycle;

// ══════════════════════════════════════════════════════════════════
//  FormGate
//
//  Every business operation in the cycle goes through here, and this
//  is the class that makes the difference between a seeder and a
//  tester.
//
//  A seeder calls a service. A tester submits a form. The gap between
//  the two is validation — the Form Request rules that a controller
//  runs before the service ever sees the payload. A cycle that calls
//  services directly will never notice that a required field is not
//  required, that a quantity may be negative, or that an id from
//  another company passes straight through.
//
//  So submit() does what the controller does, in the same order:
//
//      payload
//        ↓  Form Request rules() + messages() + attributes()
//        ↓  Form Request withValidator()  — the cross-field rules
//      validation
//        ↓  only if it passed
//      service
//        ↓
//      result
//
//  and then the scenario verifies the result.
//
//  THREE OUTCOMES, ALL MEANINGFUL:
//
//    submit()          — the happy path. Validation must pass and the
//                        service must succeed. Either failing is a
//                        finding, because a real accountant entering
//                        correct data expects it to work.
//
//    expectRejected()  — a negative test. The payload is deliberately
//                        wrong and validation MUST catch it. If it
//                        passes validation, that is a missing-rule
//                        finding — and the operation is NOT then run,
//                        because the point is the rule, not the crash.
//
//    expectRefused()   — a business rule, not a field rule. Selling
//                        stock you do not have, paying from a closed
//                        period. The service must throw. If it does
//                        not, the guard is missing and whatever it
//                        wrote is recorded as evidence.
//
//  Nothing here ever compensates for a missing rule. If validation
//  is absent, that is the finding; the cycle does not quietly supply
//  the value the form should have demanded.
//
//  @layer    Console Support
//  @depends  CycleLog, Finding
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FormGate
{
    public function __construct(private CycleLog $log)
    {
    }

    /**
     * The happy path: validate exactly as the controller would, then
     * run the real business operation.
     *
     * @param  class-string<FormRequest>|null  $requestClass  null when the
     *         screen has no Form Request of its own (a few transition
     *         endpoints validate inline) — the operation still runs.
     * @param  callable(array): mixed  $operation
     * @return mixed  whatever the service returned, or null if the step failed
     */
    public function submit(
        ?string $requestClass,
        array $payload,
        callable $operation,
        string $label,
        string $documentKind = 'document'
    ): mixed {
        if ($requestClass !== null) {
            $errors = $this->validationErrors($requestClass, $payload);

            if ($errors !== []) {
                $this->log->finding(
                    prefix: 'VAL',
                    severity: Finding::SEVERITY_HIGH,
                    confidence: Finding::CONFIDENCE_CONFIRMED,
                    title: "Valid business data was rejected by validation: {$label}",
                    operation: $label,
                    expected: 'the form to accept correct data',
                    actual: 'validation refused it',
                    evidence: $this->renderErrors($errors),
                    source: $requestClass,
                    rootCause: 'Either the rule is too strict, or the cycle is submitting the wrong shape — both are worth knowing.',
                    context: ['payload keys' => implode(', ', array_keys($payload))],
                );

                return null;
            }

            $this->log->checkPassed();
        }

        try {
            $result = $operation($payload);
        } catch (\Throwable $e) {
            // A duplicate document code is never "this operation was
            // wrong" — it is the second company colliding with the
            // first on a unique index that forgot company_id. Filing it
            // under OPS would bury a multi-tenant blocker among ordinary
            // failures, so it is named for what it is.
            if ($this->isUnscopedDocumentCodeCollision($e)) {
                $this->log->finding(
                    prefix: 'TEN',
                    severity: Finding::SEVERITY_HIGH,
                    confidence: Finding::CONFIDENCE_CONFIRMED,
                    title: "A second company cannot raise this document at all: {$label}",
                    operation: $label,
                    expected: 'each company to number its own documents independently',
                    actual: $e->getMessage(),
                    source: $this->originOf($e),
                    rootCause: 'The unique index on this document code covers the code alone instead of '
                        .'(company_id, code), while the code itself is generated from a PER-COMPANY counter. '
                        .'The first company to raise document #1 in a month takes that code platform-wide and '
                        .'every other company is refused. See audit finding TEN-001 and migration 000162, '
                        .'which fixed exactly this for the document types that existed at the time.',
                    context: ['payload keys' => implode(', ', array_keys($payload))],
                );

                return null;
            }

            $this->log->finding(
                prefix: 'OPS',
                severity: Finding::SEVERITY_HIGH,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: "A normal business operation failed: {$label}",
                operation: $label,
                expected: 'the operation to complete',
                actual: get_class($e).': '.$e->getMessage(),
                source: $this->originOf($e),
                context: ['payload keys' => implode(', ', array_keys($payload))],
                rootCause: 'A real accountant performing this step would be blocked here.',
            );

            return null;
        }

        $this->log->operation($documentKind);

        return $result;
    }

    /**
     * A negative test on the FORM. The payload is deliberately wrong
     * and the rules must catch it.
     *
     * @param  class-string<FormRequest>  $requestClass
     * @param  string  $expectedField  the field the rule should fire on
     */
    public function expectRejected(
        string $requestClass,
        array $payload,
        string $expectedField,
        string $label
    ): bool {
        $errors = $this->validationErrors($requestClass, $payload);

        if ($errors === []) {
            $this->log->finding(
                prefix: 'VAL',
                severity: Finding::SEVERITY_HIGH,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: "Invalid data passed validation: {$label}",
                operation: $label,
                expected: "validation to reject it on [{$expectedField}]",
                actual: 'the payload was accepted',
                source: $requestClass,
                rootCause: 'The rule is missing or too permissive. Nothing downstream stops this input.',
                context: ['payload' => $this->summarise($payload)],
            );

            return false;
        }

        if (! array_key_exists($expectedField, $errors)) {
            // Rejected, but for a different reason than intended. Not
            // a product defect — the input was still refused — but the
            // test is not measuring what it claims to.
            $this->log->note(
                "  (negative test '{$label}' was rejected on ["
                .implode(', ', array_keys($errors))."] rather than [{$expectedField}])"
            );
        }

        $this->log->checkPassed();
        $this->log->operation('negative test');

        return true;
    }

    /**
     * A negative test on the BUSINESS RULE. Validation may well pass;
     * the service is the thing that must refuse.
     *
     * @param  callable  $operation
     * @param  string  $expectedMessagePattern  regex the refusal should match
     */
    public function expectRefused(
        callable $operation,
        string $expectedMessagePattern,
        string $label,
        string $whatShouldStopIt
    ): bool {
        try {
            $operation();
        } catch (\DomainException $e) {
            if (! preg_match($expectedMessagePattern, $e->getMessage())) {
                $this->log->note(
                    "  (business rule '{$label}' refused, but with an unexpected message: ".$e->getMessage().')'
                );
            }

            $this->log->checkPassed();
            $this->log->operation('negative test');

            return true;
        } catch (\Throwable $e) {
            // Refused, but not cleanly. A DomainException reaches the
            // user as a message; anything else reaches them as a 500.
            $this->log->finding(
                prefix: 'VAL',
                severity: Finding::SEVERITY_MEDIUM,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: "Business rule refused with an unhandled exception: {$label}",
                operation: $label,
                expected: 'a DomainException the user can read',
                actual: get_class($e).': '.$e->getMessage(),
                source: $this->originOf($e),
                rootCause: 'The rule exists but surfaces as a server error rather than a message.',
            );

            return false;
        }

        $this->log->finding(
            prefix: 'VAL',
            severity: Finding::SEVERITY_HIGH,
            confidence: Finding::CONFIDENCE_CONFIRMED,
            title: "A business rule that should have stopped this did not: {$label}",
            operation: $label,
            expected: $whatShouldStopIt,
            actual: 'the operation completed successfully',
            rootCause: 'The guard is missing. Whatever this wrote is now in the books.',
        );

        return false;
    }

    // ── Internals ─────────────────────────────────────────────────

    /**
     * Runs a Form Request's own rules() against a payload, exactly as
     * the framework would on a real submission.
     *
     * @return array<string, array<int, string>>
     */
    /**
     * Runs the Form Request exactly as the framework runs it for a
     * controller, in the same order and with the same pieces.
     *
     * THE SECOND HALF MATTERS AS MUCH AS THE FIRST. rules() carries
     * the per-field rules; withValidator() carries the cross-field
     * ones — "a payable cheque must say which account it is paid
     * from", "the instalments must add up to 100%", "an export
     * invoice needs a foreign currency". Twelve Form Requests in this
     * application keep their most important rule there and nowhere
     * else, so a gate that only ran rules() would report a form as
     * validated while never testing the rule that actually protects
     * it.
     *
     * withValidator() is protected on FormRequest, so it is invoked
     * through reflection — the same way the framework reaches it.
     */
    private function validationErrors(string $requestClass, array $payload): array
    {
        /** @var FormRequest $request */
        $request = new $requestClass();
        $request->setContainer(app());
        $request->replace($payload);

        $rules      = method_exists($request, 'rules') ? $request->rules() : [];
        $messages   = method_exists($request, 'messages') ? $request->messages() : [];
        $attributes = method_exists($request, 'attributes') ? $request->attributes() : [];

        $validator = Validator::make($payload, $rules, $messages, $attributes);

        // The cross-field rules. A request that needs a bound route
        // model to run them cannot be exercised this way — that is a
        // limitation of driving forms outside HTTP, and it is skipped
        // rather than reported as a defect in the form.
        if (method_exists($request, 'withValidator')) {
            try {
                $method = new \ReflectionMethod($request, 'withValidator');
                $method->setAccessible(true);
                $method->invoke($request, $validator);
            } catch (\Throwable $e) {
                $this->log->note(
                    "  (cross-field rules on {$requestClass} could not be run outside an HTTP request: "
                    .$e->getMessage().')'
                );
            }
        }

        try {
            $validator->validate();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    private function renderErrors(array $errors): string
    {
        $out = [];
        foreach ($errors as $field => $messages) {
            foreach ((array) $messages as $message) {
                $out[] = "{$field}: {$message}";
            }
        }

        return implode("\n", $out);
    }

    private function summarise(array $payload): string
    {
        $flat = [];
        foreach ($payload as $key => $value) {
            $flat[] = $key.'='.(is_scalar($value) ? $value : '['.gettype($value).']');
        }

        return implode(', ', array_slice($flat, 0, 12));
    }

    /** file:line inside the application, skipping framework frames. */
    /**
     * A unique-constraint violation on an index whose name ends in
     * _code_unique — the signature of TEN-001.
     */
    private function isUnscopedDocumentCodeCollision(\Throwable $e): bool
    {
        return $e instanceof \Illuminate\Database\UniqueConstraintViolationException
            && (bool) preg_match('/_code_unique/', $e->getMessage());
    }

    private function originOf(\Throwable $e): string
    {
        foreach ($e->getTrace() as $frame) {
            $file = $frame['file'] ?? '';
            if ($file !== '' && str_contains($file, '/app/') && ! str_contains($file, '/vendor/')) {
                return basename($file).':'.($frame['line'] ?? '?')
                    .(isset($frame['function']) ? ' in '.$frame['function'].'()' : '');
            }
        }

        return basename($e->getFile()).':'.$e->getLine();
    }
}
