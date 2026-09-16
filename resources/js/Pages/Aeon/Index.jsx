import React, { useState, useEffect } from 'react';
import { Head, usePage, router } from '@inertiajs/react';
import { Flex, Box, Text, Heading, Badge, Button } from '@radix-ui/themes';
import { Plus, History, Download, MapPin, Radio, ShieldCheck, Activity, Cpu, Sparkles, Send } from 'lucide-react';
import App from '../../Layouts/App.jsx';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import AeonConversation from '../../aeon/AeonConversation.jsx';
import AeonAura from '../../aeon/AeonAura.jsx';
import AeonCore from '../../aeon/AeonCore.jsx';
import { useAeon } from '../../aeon/useAeon.js';
import { fetchAeonConversations } from '../../aeon/aeonClient.js';

export default function AeonConsole() {
  const { auth } = usePage().props;
  const aeon = useAeon();
  const [conversations, setConversations] = useState([]);
  /* Each tab is a distinct workspace, so it belongs in the URL. */
  const f = useQueryFilters({ mode: 'client', defaults: { tab: 'chat' }, debounceKeys: [] });
  const activeTab = f.values.tab;
  const setActiveTab = (value) => f.set('tab', value);

  useEffect(() => {
    fetchAeonConversations()
      .then((data) => setConversations(data || []))
      .catch(() => setConversations([]));
  }, [aeon.conversationId]);

  return (
    <App>
      <Head title="Aeon Mission Control - DBEDC Guardian" />

      <div
        style={{
          display: 'grid',
          gridTemplateColumns: '260px 1fr 300px',
          gap: '16px',
          height: 'calc(100vh - 110px)',
          padding: '12px 16px',
          maxWidth: '1680px',
          margin: '0 auto',
          boxSizing: 'border-box',
        }}
      >
        {/* Left Column: Conversations & Token Budget */}
        <aside
          style={{
            background: 'var(--color-surface, rgba(13, 17, 28, 0.95))',
            borderRadius: '16px',
            border: '1px solid var(--gray-4, rgba(255,255,255,0.08))',
            boxShadow: '0 8px 32px rgba(0,0,0,0.3)',
            display: 'flex',
            flexDirection: 'column',
            overflow: 'hidden',
          }}
        >
          <div style={{ padding: '14px 16px', borderBottom: '1px solid var(--gray-4, rgba(255,255,255,0.06))', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
              <History size={16} color="var(--accent-11, #22e3ff)" />
              <span style={{ fontSize: '13px', fontWeight: 600, color: 'var(--gray-12)' }}>Dialogues</span>
            </div>
            <Button
              size="1"
              variant="soft"
              color="cyan"
              onClick={aeon.newChat}
              style={{ cursor: 'pointer', fontSize: '11px' }}
            >
              <Plus size={13} /> New Chat
            </Button>
          </div>

          <div style={{ flex: 1, overflowY: 'auto', padding: '8px' }}>
            {conversations.length === 0 ? (
              <div style={{ padding: '32px 16px', textAlign: 'center', fontSize: '12px', color: 'var(--gray-9)' }}>
                No past dialogues recorded.
              </div>
            ) : (
              conversations.map((c) => (
                <div
                  key={c.id}
                  onClick={() => aeon.selectConversation(c.id)}
                  style={{
                    padding: '10px 12px',
                    borderRadius: '10px',
                    marginBottom: '4px',
                    cursor: 'pointer',
                    fontSize: '12px',
                    transition: 'all 0.15s ease',
                    background: c.id === aeon.conversationId ? 'var(--accent-3, rgba(34,227,255,0.12))' : 'transparent',
                    color: c.id === aeon.conversationId ? 'var(--accent-11, #22e3ff)' : 'var(--gray-11)',
                    border: c.id === aeon.conversationId ? '1px solid var(--accent-6, rgba(34,227,255,0.3))' : '1px solid transparent',
                  }}
                >
                  <div style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', fontWeight: 500 }}>
                    {c.title || `Conversation #${c.id}`}
                  </div>
                  <div style={{ fontSize: '10px', color: 'var(--gray-8)', marginTop: '2px' }}>
                    {new Date(c.updated_at).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}
                  </div>
                </div>
              ))
            )}
          </div>

          {/* Token Meter Card */}
          <div style={{ padding: '14px', borderTop: '1px solid var(--gray-4, rgba(255,255,255,0.06))', background: 'rgba(0,0,0,0.2)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '11px', marginBottom: '6px' }}>
              <span style={{ color: 'var(--gray-10)' }}>Daily Token Quota</span>
              <span style={{ color: 'var(--accent-11, #22e3ff)', fontWeight: 600 }}>
                {aeon.usage?.remaining ? `${Math.round(aeon.usage.remaining / 1000)}k left` : 'Active'}
              </span>
            </div>
            <div style={{ height: '5px', borderRadius: '9999px', background: 'rgba(255,255,255,0.1)', overflow: 'hidden' }}>
              <div
                style={{
                  height: '100%',
                  width: `${Math.min(100, Math.max(10, ((aeon.usage?.used || 0) / (aeon.usage?.limit || 500000)) * 100))}%`,
                  background: 'linear-gradient(90deg, #22e3ff, #8c6bff)',
                  borderRadius: '9999px',
                }}
              />
            </div>
          </div>
        </aside>

        {/* Center Column: Mission Control AI Workspace */}
        <main
          style={{
            position: 'relative',
            background: 'var(--color-surface, rgba(13, 17, 28, 0.95))',
            borderRadius: '16px',
            border: '1px solid var(--gray-4, rgba(255,255,255,0.08))',
            boxShadow: '0 8px 32px rgba(0,0,0,0.3)',
            display: 'flex',
            flexDirection: 'column',
            overflow: 'hidden',
          }}
        >
          <AeonAura />

          {/* Console Header */}
          <header className="aeon-header" style={{ padding: '12px 18px' }}>
            <div className="aeon-header-brand">
              <AeonCore state={aeon.sending ? 'thinking' : 'listening'} size={36} />
              <div>
                <div className="aeon-header-title" style={{ fontSize: '15px' }}>
                  <span>Aeon Mission Control</span>
                  <span className="aeon-header-badge">Omni-Model</span>
                </div>
                <div style={{ fontSize: '11px', color: 'var(--gray-10)', marginTop: '2px', display: 'flex', alignItems: 'center', gap: '8px' }}>
                  <span>DBEDC Expressway Intelligence</span>
                  <span>•</span>
                  <span style={{ color: '#22c55e', display: 'flex', alignItems: 'center', gap: '3px' }}>
                    <span style={{ width: '6px', height: '6px', borderRadius: '50%', background: '#22c55e' }} />
                    Live Grounded
                  </span>
                </div>
              </div>
            </div>

            <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
              <Badge size="2" color="cyan" variant="surface">
                Expressway v4.0
              </Badge>
            </div>
          </header>

          {/* Chat Workspace */}
          <div style={{ position: 'relative', zIndex: 10, flex: 1, minHeight: 0 }}>
            <AeonConversation
              messages={aeon.messages}
              sending={aeon.sending}
              stage={aeon.stage}
              usage={aeon.usage}
              onSend={aeon.send}
              onAction={(evt) => {
                if (evt?.block?.route) {
                  router.visit(evt.block.route);
                }
              }}
              onFeedback={aeon.feedback}
              user={auth?.user}
              hasAnimated={aeon.hasAnimated}
              markAnimated={aeon.markAnimated}
            />
          </div>
        </main>

        {/* Right Column: Live Telemetry & Expressway Monitor */}
        <aside
          style={{
            background: 'var(--color-surface, rgba(13, 17, 28, 0.95))',
            borderRadius: '16px',
            border: '1px solid var(--gray-4, rgba(255,255,255,0.08))',
            boxShadow: '0 8px 32px rgba(0,0,0,0.3)',
            display: 'flex',
            flexDirection: 'column',
            overflowY: 'auto',
            padding: '16px',
            gap: '14px',
          }}
        >
          {/* Header */}
          <div style={{ display: 'flex', alignItems: 'center', gap: '8px', borderBottom: '1px solid var(--gray-4, rgba(255,255,255,0.06))', paddingBottom: '10px' }}>
            <Activity size={16} color="var(--accent-11, #22e3ff)" />
            <span style={{ fontSize: '13px', fontWeight: 600, color: 'var(--gray-12)' }}>Live System Telemetry</span>
          </div>

          {/* Alignment Map Tracker */}
          <Box style={{ background: 'rgba(255,255,255,0.02)', border: '1px solid var(--gray-4, rgba(255,255,255,0.08))', borderRadius: '12px', padding: '12px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' }}>
              <span style={{ fontSize: '11px', fontWeight: 600, color: 'var(--accent-11, #22e3ff)' }}>Expressway Alignment</span>
              <span style={{ fontSize: '10px', color: 'var(--gray-9)' }}>48.00 km</span>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: '6px', fontSize: '11px' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', color: 'var(--gray-11)' }}>
                <span>Ch 0+000 Joydebpur</span>
                <Badge size="1" color="green" variant="soft">Clear</Badge>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between', color: 'var(--gray-11)' }}>
                <span>Ch 14+200 Toll 1 (Bhulta)</span>
                <Badge size="1" color="cyan" variant="soft">ETC Flow</Badge>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between', color: 'var(--gray-11)' }}>
                <span>Ch 28+500 Kanchan TMC</span>
                <Badge size="1" color="green" variant="soft">Normal</Badge>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between', color: 'var(--gray-11)' }}>
                <span>Ch 48+000 Madanpur</span>
                <Badge size="1" color="green" variant="soft">Clear</Badge>
              </div>
            </div>
          </Box>

          {/* Quick Metrics */}
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px' }}>
            <Box style={{ padding: '10px', background: 'rgba(255,255,255,0.02)', border: '1px solid var(--gray-4, rgba(255,255,255,0.08))', borderRadius: '10px' }}>
              <div style={{ fontSize: '10px', color: 'var(--gray-9)' }}>Active Patrols</div>
              <div style={{ fontSize: '16px', fontWeight: 700, color: 'var(--accent-11, #22e3ff)', marginTop: '2px' }}>6 Units</div>
            </Box>

            <Box style={{ padding: '10px', background: 'rgba(255,255,255,0.02)', border: '1px solid var(--gray-4, rgba(255,255,255,0.08))', borderRadius: '10px' }}>
              <div style={{ fontSize: '10px', color: 'var(--gray-9)' }}>Open NCRs</div>
              <div style={{ fontSize: '16px', fontWeight: 700, color: '#f59e0b', marginTop: '2px' }}>3 Active</div>
            </Box>

            <Box style={{ padding: '10px', background: 'rgba(255,255,255,0.02)', border: '1px solid var(--gray-4, rgba(255,255,255,0.08))', borderRadius: '10px' }}>
              <div style={{ fontSize: '10px', color: 'var(--gray-9)' }}>ADMS Punch Sync</div>
              <div style={{ fontSize: '16px', fontWeight: 700, color: '#22c55e', marginTop: '2px' }}>100% Live</div>
            </Box>

            <Box style={{ padding: '10px', background: 'rgba(255,255,255,0.02)', border: '1px solid var(--gray-4, rgba(255,255,255,0.08))', borderRadius: '10px' }}>
              <div style={{ fontSize: '10px', color: 'var(--gray-9)' }}>ETC Adoption</div>
              <div style={{ fontSize: '16px', fontWeight: 700, color: 'var(--accent-11, #22e3ff)', marginTop: '2px' }}>68.4%</div>
            </Box>
          </div>

          {/* Prompt Shortcuts */}
          <div style={{ marginTop: 'auto', display: 'flex', flexDirection: 'column', gap: '6px' }}>
            <span style={{ fontSize: '11px', fontWeight: 600, color: 'var(--gray-10)' }}>Autonomous Audits</span>
            <Button
              size="1"
              variant="outline"
              color="gray"
              onClick={() => aeon.send('Run a full executive health check across expressway operations, NCRs, and attendance')}
              style={{ cursor: 'pointer', fontSize: '11px', justifyContent: 'flex-start', textAlign: 'left' }}
            >
              ⚡ Executive Health Check
            </Button>
            <Button
              size="1"
              variant="outline"
              color="gray"
              onClick={() => aeon.send('Audit all open NCRs and RFIs for structural packages')}
              style={{ cursor: 'pointer', fontSize: '11px', justifyContent: 'flex-start', textAlign: 'left' }}
            >
              🔍 QC & Structural Audit
            </Button>
          </div>
        </aside>
      </div>
    </App>
  );
}
