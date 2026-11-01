# Prompt for ChatGPT — second-opinion review of v1.171.5

Context: FlxCoreHC is a healthcare credentialing **tracker** (not NCQA-grade; label "Production Candidate"). v1.171.5 answers Claude Code's independent runtime validation of v1.171.4 (items A–R). Read UPDATE_FOR_CHATGPT_v1_171_5.md first.
Please review, without proposing new features:
1. Is each disposition (A–R) supported by its regression test being a real behavioural DB/HTTP test (not a source grep)? Flag any that are not.
2. Decision G (throttle): are 8 guesses per address / 12 per account from unknown addresses, with refused attempts not counted toward the account limit, an acceptable trade-off? Is the shared-NAT 15-minute hold acceptable? Recommend keep/tighten.
3. Risks of `flx_flash_type` (message-regex toast typing) — suggest whether explicit types at call sites are worth a later release.
4. Anything in section "Not done" that you would upgrade to required.
5. Any claim in the memo that overstates what was verified (sandbox PASS is not independent PASS).
Return: a short list of required changes vs. optional ones. Do not suggest features.
