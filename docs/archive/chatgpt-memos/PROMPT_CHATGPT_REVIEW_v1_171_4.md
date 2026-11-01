# Prompt for ChatGPT — re-review of FlxCoreHC v1.171.4 (paste as-is; attach the zip, UPDATE_FOR_CHATGPT_v1_171_4.md and runtime_check_output_v1.171.4.txt)

You reviewed v1.171.3 and found one blocker plus smaller issues. v1.171.4 is the remediation release (no new features). FlxCoreHC is a healthcare credentialing/compliance **tracker**, not NCQA-grade credentialing; do not ask for NPDB, committees, NCQA timeliness or PSV automation.

1. Start with `UPDATE_FOR_CHATGPT_v1_171_4.md`. For each of your findings, decide whether the stated fix and test actually close it. Say "closed", "partly closed" or "not closed" with the reason.
2. Try to break the fixes: `database/encrypt_existing_addresses.php` (any combination where a value is lost or plaintext left), `TeamService::acceptInvite` (atomic consume), migration `132_pr_regulatory_wording.sql` (idempotency, column-length limits, rename collisions), and the reworded UI/help.
3. Search the code for remaining overclaims: anything implying FlxCoreHC itself performs/certifies verification, or makes a legal compliance determination ("compliant", "certified", "verified by FlxCoreHC", "accepted by payers").
4. Regulatory table: for each row marked Unverified, give the primary source (URL + exact article/section) if you can find it; if you cannot, say so. Do not assert regulatory facts from memory.
5. List anything in my evidence labels that overstates: I claim only "Claude.ai sandbox PASS", never an independent PASS.
6. Output: findings table (severity · file/function · failing scenario · minimal fix · confidence · needs runtime proof?), then a short go/no-go statement for calling this a Production Candidate.
