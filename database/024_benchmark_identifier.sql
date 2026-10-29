-- Clarify an ambiguous exact-match extraction question without rewriting historical snapshots.
WITH changed AS (
    UPDATE benchmark_questions q
    SET input=jsonb_set(q.input,'{prompt}',to_jsonb('Extract from: Product Z has 0 units on hand and is discontinued. The product field must contain only the product identifier, without the word Product.'::text))
    FROM benchmark_tests t
    WHERE q.testid=t.id AND t.name='structured-extraction' AND q.name='Do not treat zero as missing'
      AND q.input->>'prompt'='Extract from: Product Z has 0 units on hand and is discontinued.'
      AND q.scoring_type='matches' AND q.scoring_criteria='{"product":"Z","stock":0,"discontinued":true}'::jsonb
    RETURNING q.testid
)
UPDATE benchmark_tests SET revision=revision+1,updated_at=NOW() WHERE id IN (SELECT testid FROM changed);
