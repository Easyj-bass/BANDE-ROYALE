/* ══════════════════════════════════════════════════
   BAND ROYAL — Configuration Supabase

   ➜ Remplacez les deux valeurs ci-dessous par celles
     de votre projet Supabase :
     https://supabase.com → votre projet → Settings → API
══════════════════════════════════════════════════ */

const SUPABASE_URL      = 'https://iuotzhstlcivgcnshuoq.supabase.co';
const SUPABASE_ANON_KEY = 'sb_publishable__0Yz1xxeDccsKTANwI1oUw_IaJAMFrA';

/* ── Initialisation ── */
const { createClient } = window.supabase;
const db = createClient(SUPABASE_URL, SUPABASE_ANON_KEY);

function isConfigured() {
  return SUPABASE_URL !== 'VOTRE_URL_SUPABASE' && SUPABASE_ANON_KEY !== 'VOTRE_CLE_ANON_SUPABASE';
}

/* ══════════════════════════════════════════════════
   FINANCE — Transactions
══════════════════════════════════════════════════ */

async function dbGetFinance() {
  if (!isConfigured()) throw new Error('Supabase non configuré — modifiez supabase-client.js');

  const { data, error } = await db
    .from('transactions')
    .select('id, type, description, date, montant, event_id, transaction_details(id, nom, montant, motif)')
    .order('date', { ascending: false })
    .order('id',   { ascending: false });

  if (error) throw new Error(error.message);

  const cred = [], deb = [];
  for (const t of data) {
    const entry = {
      id:       t.id,
      desc:     t.description,
      date:     t.date,
      amt:      parseFloat(t.montant || 0),
      details:  (t.transaction_details && t.transaction_details.length > 0)
                  ? t.transaction_details.map(d => ({
                      id:      d.id,
                      nom:     d.nom,
                      montant: parseFloat(d.montant || 0),
                      motif:   d.motif,
                    }))
                  : null,
      event_id: t.event_id || null,
    };
    if (t.type === 'cred') cred.push(entry);
    else                   deb.push(entry);
  }
  return { cred, deb };
}

async function dbAddTransaction(type, desc, date, amt, details = []) {
  const { data, error } = await db
    .from('transactions')
    .insert({ type, description: desc, date, montant: amt })
    .select('id')
    .single();
  if (error) throw new Error(error.message);

  if (details.length > 0) {
    const { error: err2 } = await db.from('transaction_details').insert(
      details.map(d => ({ transaction_id: data.id, nom: d.nom, montant: d.montant || 0, motif: d.motif || '—' }))
    );
    if (err2) throw new Error(err2.message);
  }
  return data.id;
}

async function dbEditTransaction(id, desc, date, amt, details = []) {
  const { error } = await db
    .from('transactions')
    .update({ description: desc, date, montant: amt })
    .eq('id', id);
  if (error) throw new Error(error.message);

  await db.from('transaction_details').delete().eq('transaction_id', id);

  if (details.length > 0) {
    const { error: err2 } = await db.from('transaction_details').insert(
      details.map(d => ({ transaction_id: id, nom: d.nom, montant: d.montant || 0, motif: d.motif || '—' }))
    );
    if (err2) throw new Error(err2.message);
  }
}

async function dbDeleteTransaction(id) {
  const { error } = await db.from('transactions').delete().eq('id', id);
  if (error) throw new Error(error.message);
}

/* ══════════════════════════════════════════════════
   PROGRAMME — Événements
══════════════════════════════════════════════════ */

async function dbGetEvents() {
  if (!isConfigured()) throw new Error('Supabase non configuré — modifiez supabase-client.js');

  const { data, error } = await db
    .from('events')
    .select('id, nom, type, date, lieu, description, image_path, event_musicians(id, nom, instrument)')
    .order('date', { ascending: false })
    .order('id',   { ascending: false });

  if (error) throw new Error(error.message);
  return data.map(e => ({ ...e, musicians: e.event_musicians || [] }));
}

async function dbSaveEvent(nom, type, date, lieu, description, image_path, musicians) {
  const TYPE_LABELS = {
    concert:'Concert', cabaret:'Cabaret', acoustique:'Acoustique',
    mariage:'Mariage', funerail:'Funérailles', autre:'Événement',
  };

  // 1. Insérer l'événement
  const { data: ev, error: evErr } = await db
    .from('events')
    .insert({ nom, type, date, lieu, description, image_path })
    .select('id')
    .single();
  if (evErr) throw new Error(evErr.message);

  // 2. Insérer les musiciens
  const valid = musicians.filter(m => m.nom && m.nom.trim());
  if (valid.length > 0) {
    const { error: muErr } = await db.from('event_musicians').insert(
      valid.map(m => ({ event_id: ev.id, nom: m.nom.trim(), instrument: m.instrument || '' }))
    );
    if (muErr) throw new Error(muErr.message);
  }

  // 3. Créer automatiquement l'entrée financière (créditeur)
  const txDesc = `${nom} — ${TYPE_LABELS[type] || type}`;
  const { data: tx, error: txErr } = await db
    .from('transactions')
    .insert({ type: 'cred', description: txDesc, date, montant: 0, event_id: ev.id })
    .select('id')
    .single();
  if (txErr) throw new Error(txErr.message);

  // 4. Une ligne de détail par musicien (montant = 0, à remplir après)
  if (valid.length > 0) {
    const { error: dtErr } = await db.from('transaction_details').insert(
      valid.map(m => ({ transaction_id: tx.id, nom: m.nom.trim(), montant: 0, motif: 'Cotisation 500 FCFA à verser' }))
    );
    if (dtErr) throw new Error(dtErr.message);
  }

  return { event_id: ev.id, finance_id: tx.id };
}

async function dbEditEvent(id, nom, type, date, lieu, description, image_path, musicians) {
  const TYPE_LABELS = {
    concert:'Concert', cabaret:'Cabaret', acoustique:'Acoustique',
    mariage:'Mariage', funerail:'Funérailles', autre:'Événement',
  };

  // Mettre à jour l'événement
  const { error } = await db.from('events')
    .update({ nom, type, date, lieu, description, image_path })
    .eq('id', id);
  if (error) throw new Error(error.message);

  // Remplacer les musiciens
  await db.from('event_musicians').delete().eq('event_id', id);
  const valid = musicians.filter(m => m.nom && m.nom.trim());
  if (valid.length > 0) {
    await db.from('event_musicians').insert(
      valid.map(m => ({ event_id: id, nom: m.nom.trim(), instrument: m.instrument || '' }))
    );
  }

  // Synchroniser l'entrée financière liée
  const { data: tx } = await db.from('transactions')
    .select('id')
    .eq('event_id', id)
    .eq('type', 'cred')
    .maybeSingle();

  if (tx) {
    await db.from('transactions')
      .update({ description: `${nom} — ${TYPE_LABELS[type] || type}`, date })
      .eq('id', tx.id);
    await db.from('transaction_details').delete().eq('transaction_id', tx.id);
    if (valid.length > 0) {
      await db.from('transaction_details').insert(
        valid.map(m => ({ transaction_id: tx.id, nom: m.nom.trim(), montant: 0, motif: 'Cotisation 500 FCFA à verser' }))
      );
    }
  }
}

async function dbDeleteEvent(id) {
  await db.from('transactions').update({ event_id: null }).eq('event_id', id);
  const { error } = await db.from('events').delete().eq('id', id);
  if (error) throw new Error(error.message);
}

/* ══════════════════════════════════════════════════
   IMAGE — Supabase Storage
══════════════════════════════════════════════════ */

async function dbUploadImage(file) {
  const ext      = file.name.split('.').pop().toLowerCase();
  const filename = `evt_${Date.now()}.${ext}`;

  const { error } = await db.storage
    .from('event-images')
    .upload(filename, file, { cacheControl: '3600', upsert: false });

  if (error) throw new Error('Upload image : ' + error.message);

  const { data } = db.storage.from('event-images').getPublicUrl(filename);
  return data.publicUrl;
}
