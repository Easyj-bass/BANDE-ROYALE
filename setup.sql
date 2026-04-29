-- ══════════════════════════════════════════════════════
--  BAND ROYAL — Setup Supabase
--
--  Instructions :
--  1. Allez sur https://supabase.com → votre projet
--  2. Cliquez sur "SQL Editor" dans le menu gauche
--  3. Copiez-collez TOUT ce fichier et cliquez "Run"
-- ══════════════════════════════════════════════════════


-- ── Tables ──────────────────────────────────────────

CREATE TABLE IF NOT EXISTS events (
  id          BIGSERIAL PRIMARY KEY,
  nom         TEXT      NOT NULL,
  type        TEXT      NOT NULL DEFAULT 'autre',
  date        DATE      NOT NULL,
  lieu        TEXT      DEFAULT '',
  description TEXT      DEFAULT '',
  image_path  TEXT      DEFAULT '',
  created_at  TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS event_musicians (
  id         BIGSERIAL PRIMARY KEY,
  event_id   BIGINT    NOT NULL REFERENCES events(id) ON DELETE CASCADE,
  nom        TEXT      NOT NULL,
  instrument TEXT      DEFAULT ''
);

CREATE TABLE IF NOT EXISTS transactions (
  id          BIGSERIAL PRIMARY KEY,
  type        TEXT      NOT NULL CHECK (type IN ('cred', 'deb')),
  description TEXT      NOT NULL,
  date        DATE      NOT NULL,
  montant     NUMERIC(12,2) DEFAULT 0,
  event_id    BIGINT    DEFAULT NULL REFERENCES events(id) ON DELETE SET NULL,
  created_at  TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS transaction_details (
  id               BIGSERIAL PRIMARY KEY,
  transaction_id   BIGINT    NOT NULL REFERENCES transactions(id) ON DELETE CASCADE,
  nom              TEXT      NOT NULL,
  montant          NUMERIC(12,2) DEFAULT 0,
  motif            TEXT      DEFAULT '—'
);


-- ── Sécurité (Row Level Security) ───────────────────
--  Permet l'accès public en lecture et écriture
--  (la protection admin se fait côté navigateur via le code PIN)

ALTER TABLE events              ENABLE ROW LEVEL SECURITY;
ALTER TABLE event_musicians     ENABLE ROW LEVEL SECURITY;
ALTER TABLE transactions        ENABLE ROW LEVEL SECURITY;
ALTER TABLE transaction_details ENABLE ROW LEVEL SECURITY;

-- Events
CREATE POLICY "Public select"  ON events FOR SELECT USING (true);
CREATE POLICY "Public insert"  ON events FOR INSERT WITH CHECK (true);
CREATE POLICY "Public update"  ON events FOR UPDATE USING (true);
CREATE POLICY "Public delete"  ON events FOR DELETE USING (true);

-- Event musicians
CREATE POLICY "Public select"  ON event_musicians FOR SELECT USING (true);
CREATE POLICY "Public insert"  ON event_musicians FOR INSERT WITH CHECK (true);
CREATE POLICY "Public update"  ON event_musicians FOR UPDATE USING (true);
CREATE POLICY "Public delete"  ON event_musicians FOR DELETE USING (true);

-- Transactions
CREATE POLICY "Public select"  ON transactions FOR SELECT USING (true);
CREATE POLICY "Public insert"  ON transactions FOR INSERT WITH CHECK (true);
CREATE POLICY "Public update"  ON transactions FOR UPDATE USING (true);
CREATE POLICY "Public delete"  ON transactions FOR DELETE USING (true);

-- Transaction details
CREATE POLICY "Public select"  ON transaction_details FOR SELECT USING (true);
CREATE POLICY "Public insert"  ON transaction_details FOR INSERT WITH CHECK (true);
CREATE POLICY "Public update"  ON transaction_details FOR UPDATE USING (true);
CREATE POLICY "Public delete"  ON transaction_details FOR DELETE USING (true);


-- ── Storage (bucket pour les images) ────────────────
--  Exécutez cette partie séparément dans le SQL Editor
--  OU créez le bucket manuellement :
--  Storage → New Bucket → Nom : "event-images" → Public : OUI

INSERT INTO storage.buckets (id, name, public)
VALUES ('event-images', 'event-images', true)
ON CONFLICT (id) DO NOTHING;

CREATE POLICY "Public upload" ON storage.objects
  FOR INSERT WITH CHECK (bucket_id = 'event-images');

CREATE POLICY "Public read" ON storage.objects
  FOR SELECT USING (bucket_id = 'event-images');
