import type { CompanyRole, UserRole } from '../types/api'

export const ROLE_LABEL: Record<UserRole, string> = {
  admin: 'EkMira',
  owner: 'Dueño',
  operator: 'Operador',
  viewer: 'Solo consulta',
}

// Lo que puede hacer cada rol de empresa, para explicarlo al dar de alta un usuario
export const COMPANY_ROLES: { role: CompanyRole; label: string; help: string }[] = [
  { role: 'owner', label: 'Dueño', help: 'Ve todo y da de alta usuarios' },
  { role: 'operator', label: 'Operador', help: 'Ve todo y confirma servicios' },
  { role: 'viewer', label: 'Solo consulta', help: 'Ve todo, no cambia nada' },
]
